use base64::Engine;
use base64::engine::general_purpose::STANDARD;
use serde::{Deserialize, Serialize};
use time::OffsetDateTime;
use time::format_description::well_known::Rfc3339;
use url::Url;

use crate::canonical;
use crate::crypto::{constant_time_hex_equal, decode_canonical_base64, hmac_sha256_hex};
use crate::{BrokerError, BrokerResult};

pub const WIRE_VERSION: &str = "1";
pub const COMMITMENT_SCHEME: &str = "hmac-sha256-ephemeral-run-key-v1";
const MAXIMUM_EFFECT_RESPONSE_BYTES: usize = 1_048_576;
const MAXIMUM_READ_RESPONSE_BYTES: usize = 26_214_400;

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct EffectExecutionProposal {
    pub contract: String,
    pub version: String,
    pub evidence_contract: String,
    pub effect_id: String,
    pub effect_sequence: u32,
    pub profile: String,
    pub target_key: String,
    pub capability: String,
    pub semantic_effect: String,
    pub http_method: String,
    pub endpoint_template: String,
    pub provider_path: String,
    pub request_body_base64: String,
    pub connect_timeout_ms: u32,
    pub request_timeout_ms: u32,
    pub maximum_response_bytes: usize,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct ReadObservationProposal {
    pub contract: String,
    pub version: String,
    pub evidence_contract: String,
    pub observation_id: String,
    pub profile: String,
    pub target_key: String,
    pub capability: String,
    pub http_method: String,
    pub endpoint_template: String,
    pub provider_path: String,
    pub connect_timeout_ms: u32,
    pub request_timeout_ms: u32,
    pub maximum_response_bytes: usize,
}

impl ReadObservationProposal {
    pub const CONTRACT: &'static str = "cieplik206.fakturownia.brokered-read-observation-proposal";

    pub fn validate(&self, evidence_contract: &str) -> BrokerResult<()> {
        if self.contract != Self::CONTRACT
            || self.version != WIRE_VERSION
            || self.evidence_contract != evidence_contract
            || !is_lower_hex(&self.observation_id, 32)
            || self.http_method != "GET"
        {
            return Err(BrokerError::denied(
                "broker read observation uses an unsupported contract",
            ));
        }

        validate_target_key(evidence_contract, &self.profile, &self.target_key)?;
        validate_transport_bounds(
            self.connect_timeout_ms,
            self.request_timeout_ms,
            self.maximum_response_bytes,
            MAXIMUM_READ_RESPONSE_BYTES,
        )?;

        match (
            evidence_contract,
            self.capability.as_str(),
            self.endpoint_template.as_str(),
        ) {
            ("fakturownia-invoice-identity-s0.3-v1", "account.read", "/account.json")
            | ("fakturownia-ksef-demo-s0.4-v1", "account.read", "/account.json")
                if self.provider_path == "/account.json" => {}
            ("fakturownia-invoice-identity-s0.3-v1", "invoice.search", "/invoices.json")
                if valid_invoice_search_path(&self.provider_path, true) => {}
            ("fakturownia-ksef-demo-s0.4-v1", "invoice.search", "/invoices.json")
                if valid_invoice_search_path(&self.provider_path, false) => {}
            ("fakturownia-ksef-demo-s0.4-v1", "invoice.read", "/invoices/{invoice_id}.json")
                if valid_invoice_read_path(&self.provider_path) => {}
            (
                "fakturownia-ksef-demo-s0.4-v1",
                "invoice.pdf.download",
                "/invoices/{invoice_id}.pdf",
            ) if valid_invoice_pdf_path(&self.provider_path) => {}
            _ => {
                return Err(BrokerError::denied(
                    "broker read observation tuple or provider path is not allowlisted",
                ));
            }
        }

        Ok(())
    }
}

impl EffectExecutionProposal {
    pub const CONTRACT: &'static str = "cieplik206.fakturownia.brokered-effect-execution-proposal";

    pub fn validate(&self, evidence_contract: &str) -> BrokerResult<(Vec<u8>, &'static str)> {
        if self.contract != Self::CONTRACT
            || self.version != WIRE_VERSION
            || self.evidence_contract != evidence_contract
            || !is_lower_hex(&self.effect_id, 32)
        {
            return Err(BrokerError::denied(
                "broker effect proposal uses an unsupported contract",
            ));
        }

        let operation = allowed_operation(
            evidence_contract,
            &self.profile,
            &self.capability,
            &self.semantic_effect,
            &self.http_method,
            &self.endpoint_template,
        )?;

        validate_target_key(evidence_contract, &self.profile, &self.target_key)?;

        if self.effect_sequence == 0 || self.effect_sequence > operation.maximum_sequence {
            return Err(BrokerError::denied(
                "broker effect proposal exceeds its authorization budget",
            ));
        }

        validate_provider_path(operation, &self.provider_path)?;
        validate_transport_bounds(
            self.connect_timeout_ms,
            self.request_timeout_ms,
            self.maximum_response_bytes,
            MAXIMUM_EFFECT_RESPONSE_BYTES,
        )?;
        let body = decode_request_body(&self.request_body_base64, operation.body_policy, None)?;

        Ok((body, operation.body_policy))
    }
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct ConcurrentEffectExecutionProposal {
    pub contract: String,
    pub version: String,
    pub proposals: Vec<EffectExecutionProposal>,
}

impl ConcurrentEffectExecutionProposal {
    pub const CONTRACT: &'static str =
        "cieplik206.fakturownia.concurrent-effect-execution-proposal";

    pub fn validate(&self, evidence_contract: &str) -> BrokerResult<()> {
        if self.contract != Self::CONTRACT
            || self.version != WIRE_VERSION
            || self.proposals.len() != 2
        {
            return Err(BrokerError::denied(
                "concurrent broker effect requires exactly two proposals",
            ));
        }

        let first = &self.proposals[0];
        let second = &self.proposals[1];
        first.validate(evidence_contract)?;
        second.validate(evidence_contract)?;

        if evidence_contract != "fakturownia-invoice-identity-s0.3-v1"
            || first.profile != "invoice_identity"
            || first.target_key != "primary"
            || first.capability != "invoice.vat.issue"
            || first.effect_id == second.effect_id
            || first.effect_sequence == second.effect_sequence
            || first.profile != second.profile
            || first.target_key != second.target_key
            || first.capability != second.capability
            || first.semantic_effect != second.semantic_effect
            || first.http_method != second.http_method
            || first.endpoint_template != second.endpoint_template
            || first.provider_path != second.provider_path
            || first.request_body_base64 != second.request_body_base64
            || first.connect_timeout_ms != second.connect_timeout_ms
            || first.request_timeout_ms != second.request_timeout_ms
            || first.maximum_response_bytes != second.maximum_response_bytes
        {
            return Err(BrokerError::denied(
                "concurrent broker effects do not bind one exact same-OID request",
            ));
        }

        Ok(())
    }
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct EffectExecutionRequest {
    pub contract: String,
    pub version: String,
    pub descriptor: LiveEffectDescriptor,
    pub provider_path: String,
    pub request_body_base64: String,
}

impl EffectExecutionRequest {
    pub const CONTRACT: &'static str = "cieplik206.fakturownia.brokered-effect-execution-request";

    pub fn validate(&self, target_origin: &str, commitment_key: &[u8]) -> BrokerResult<Vec<u8>> {
        if self.contract != Self::CONTRACT || self.version != WIRE_VERSION {
            return Err(BrokerError::denied(
                "broker effect request uses an unsupported contract",
            ));
        }

        self.descriptor.validate()?;
        validate_target_origin(target_origin)?;
        self.descriptor
            .validate_provider_path(&self.provider_path)?;

        if commitment_key.len() != 32 {
            return Err(BrokerError::denied(
                "broker commitment key must contain exactly 32 bytes",
            ));
        }

        let body = decode_request_body(
            &self.request_body_base64,
            &self.descriptor.request_body_policy,
            Some(self.descriptor.request_body_size_bytes),
        )?;

        let target = commitment_material("target", target_origin.as_bytes());
        let operation = commitment_material(
            "operation",
            format!("{}\n{}", self.descriptor.http_method, self.provider_path).as_bytes(),
        );
        let body_material = commitment_material("request-body", &body);

        verify_commitment(
            commitment_key,
            &target,
            &self.descriptor.target_origin_hmac_sha256,
        )?;
        verify_commitment(
            commitment_key,
            &operation,
            &self.descriptor.operation_identity_hmac_sha256,
        )?;
        verify_commitment(
            commitment_key,
            &body_material,
            &self.descriptor.request_body_hmac_sha256,
        )?;

        Ok(body)
    }
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct LiveEffectDescriptor {
    pub contract: String,
    pub version: String,
    pub evidence_contract: String,
    pub run_id: String,
    pub effect_id: String,
    pub effect_sequence: u32,
    pub profile: String,
    pub target_key: String,
    pub capability: String,
    pub semantic_effect: String,
    pub http_method: String,
    pub endpoint_template: String,
    pub commitment_scheme: String,
    pub target_origin_hmac_sha256: String,
    pub operation_identity_hmac_sha256: String,
    pub request_body_hmac_sha256: String,
    pub request_body_size_bytes: usize,
    pub request_body_policy: String,
    pub launch_manifest_sha256: String,
    pub supervisor_attestation_sha256: String,
    pub broker_policy_sha256: String,
    pub authorization_set_sha256: String,
    pub authorization_bundle_sha256: String,
    pub probe_plan_sha256: String,
    pub claim_request_sha256: String,
    pub consumption_receipt_sha256: String,
    pub claim_nonce: String,
    pub run_started_at: String,
    pub connect_timeout_ms: u32,
    pub request_timeout_ms: u32,
    pub maximum_response_bytes: usize,
}

impl LiveEffectDescriptor {
    pub const CONTRACT: &'static str = "cieplik206.fakturownia.live-effect-descriptor";

    pub fn validate(&self) -> BrokerResult<()> {
        if self.contract != Self::CONTRACT
            || self.version != WIRE_VERSION
            || self.commitment_scheme != COMMITMENT_SCHEME
        {
            return Err(BrokerError::denied(
                "broker effect descriptor uses an unsupported contract",
            ));
        }

        if !is_lower_hex(&self.run_id, 32) || !is_lower_hex(&self.effect_id, 32) {
            return Err(BrokerError::denied("broker effect identity is invalid"));
        }

        let operation = self.operation()?;
        validate_target_key(&self.evidence_contract, &self.profile, &self.target_key)?;

        if self.effect_sequence == 0 || self.effect_sequence > operation.maximum_sequence {
            return Err(BrokerError::denied(
                "broker effect sequence exceeds its authorization budget",
            ));
        }

        for digest in [
            &self.target_origin_hmac_sha256,
            &self.operation_identity_hmac_sha256,
            &self.request_body_hmac_sha256,
            &self.launch_manifest_sha256,
            &self.supervisor_attestation_sha256,
            &self.broker_policy_sha256,
            &self.authorization_set_sha256,
            &self.authorization_bundle_sha256,
            &self.probe_plan_sha256,
            &self.claim_request_sha256,
            &self.consumption_receipt_sha256,
        ] {
            if !is_lower_hex(digest, 64) {
                return Err(BrokerError::denied(
                    "broker effect descriptor contains an invalid digest",
                ));
            }
        }

        decode_canonical_base64(&self.claim_nonce, 32)?;
        strict_utc_microsecond(&self.run_started_at)?;

        if self.request_body_size_bytes > 1_048_576
            || (self.request_body_policy == "required_non_empty"
                && self.request_body_size_bytes == 0)
            || (self.request_body_policy == "must_be_empty" && self.request_body_size_bytes != 0)
        {
            return Err(BrokerError::denied(
                "broker effect transport limits are invalid",
            ));
        }

        validate_transport_bounds(
            self.connect_timeout_ms,
            self.request_timeout_ms,
            self.maximum_response_bytes,
            MAXIMUM_EFFECT_RESPONSE_BYTES,
        )?;

        Ok(())
    }

    pub fn validate_provider_path(&self, path: &str) -> BrokerResult<()> {
        validate_provider_path(self.operation()?, path)
    }

    fn operation(&self) -> BrokerResult<AllowedOperation> {
        let operation = allowed_operation(
            &self.evidence_contract,
            &self.profile,
            &self.capability,
            &self.semantic_effect,
            &self.http_method,
            &self.endpoint_template,
        )?;

        if self.request_body_policy != operation.body_policy {
            return Err(BrokerError::denied(
                "broker effect request-body policy is not allowlisted",
            ));
        }

        Ok(operation)
    }
}

fn allowed_operation(
    evidence_contract: &str,
    profile: &str,
    capability: &str,
    semantic_effect: &str,
    http_method: &str,
    endpoint_template: &str,
) -> BrokerResult<AllowedOperation> {
    let operations = [
        AllowedOperation {
            evidence_contract: "fakturownia-invoice-identity-s0.3-v1",
            profiles: &["invoice_identity"],
            capability: "invoice.vat.issue",
            semantic_effect: "invoice_create",
            http_method: "POST",
            endpoint_template: "/invoices.json",
            body_policy: "required_non_empty",
            maximum_sequence: 11,
            path_kind: PathKind::InvoiceCollection,
        },
        AllowedOperation {
            evidence_contract: "fakturownia-ksef-demo-s0.4-v1",
            profiles: &[
                "explicit_block",
                "explicit_persist",
                "auto_block",
                "auto_persist",
            ],
            capability: "contract_probe.invoice.fixture.issue",
            semantic_effect: "probe_fixture_invoice_create",
            http_method: "POST",
            endpoint_template: "/invoices.json",
            body_policy: "required_non_empty",
            maximum_sequence: 8,
            path_kind: PathKind::InvoiceCollection,
        },
        AllowedOperation {
            evidence_contract: "fakturownia-ksef-demo-s0.4-v1",
            profiles: &["explicit_block", "explicit_persist"],
            capability: "invoice.ksef.ensure_accepted",
            semantic_effect: "ksef_explicit_submit",
            http_method: "GET",
            endpoint_template: "/invoices/{invoice_id}.json?send_to_ksef=yes",
            body_policy: "must_be_empty",
            maximum_sequence: 8,
            path_kind: PathKind::InvoiceKsef,
        },
    ];

    operations
        .into_iter()
        .find(|operation| {
            evidence_contract == operation.evidence_contract
                && operation.profiles.contains(&profile)
                && capability == operation.capability
                && semantic_effect == operation.semantic_effect
                && http_method == operation.http_method
                && endpoint_template == operation.endpoint_template
        })
        .ok_or_else(|| BrokerError::denied("broker effect operation tuple is not allowlisted"))
}

fn validate_target_key(
    evidence_contract: &str,
    profile: &str,
    target_key: &str,
) -> BrokerResult<()> {
    let allowed = match (evidence_contract, profile) {
        ("fakturownia-invoice-identity-s0.3-v1", "invoice_identity") => {
            matches!(target_key, "primary" | "secondary")
        }
        ("fakturownia-ksef-demo-s0.4-v1", profile) => {
            matches!(
                profile,
                "explicit_block" | "explicit_persist" | "auto_block" | "auto_persist"
            ) && target_key == profile
        }
        _ => false,
    };

    if !allowed {
        return Err(BrokerError::denied(
            "broker effect target is not allowlisted for its profile",
        ));
    }

    Ok(())
}

#[derive(Clone, Copy)]
struct AllowedOperation {
    evidence_contract: &'static str,
    profiles: &'static [&'static str],
    capability: &'static str,
    semantic_effect: &'static str,
    http_method: &'static str,
    endpoint_template: &'static str,
    body_policy: &'static str,
    maximum_sequence: u32,
    path_kind: PathKind,
}

#[derive(Clone, Copy)]
enum PathKind {
    InvoiceCollection,
    InvoiceKsef,
}

fn validate_provider_path(operation: AllowedOperation, path: &str) -> BrokerResult<()> {
    match operation.path_kind {
        PathKind::InvoiceCollection if path == "/invoices.json" => Ok(()),
        PathKind::InvoiceKsef if valid_ksef_path(path) => Ok(()),
        _ => Err(BrokerError::denied(
            "broker provider path is not allowlisted",
        )),
    }
}

fn validate_transport_bounds(
    connect_timeout_ms: u32,
    request_timeout_ms: u32,
    maximum_response_bytes: usize,
    maximum_allowed_response_bytes: usize,
) -> BrokerResult<()> {
    if connect_timeout_ms == 0
        || connect_timeout_ms > 5_000
        || request_timeout_ms < connect_timeout_ms
        || request_timeout_ms > 30_000
        || maximum_response_bytes == 0
        || maximum_response_bytes > maximum_allowed_response_bytes
    {
        return Err(BrokerError::denied("broker transport limits are invalid"));
    }

    Ok(())
}

fn decode_request_body(
    request_body_base64: &str,
    request_body_policy: &str,
    exact_bytes: Option<usize>,
) -> BrokerResult<Vec<u8>> {
    let body = STANDARD
        .decode(request_body_base64)
        .map_err(|_| BrokerError::denied("broker request body is not valid base64"))?;

    if STANDARD.encode(&body) != request_body_base64
        || body.len() > 1_048_576
        || exact_bytes.is_some_and(|expected| body.len() != expected)
    {
        return Err(BrokerError::denied(
            "broker request body binding is invalid",
        ));
    }

    if request_body_policy == "required_non_empty" {
        if body.is_empty() {
            return Err(BrokerError::denied("broker request body must not be empty"));
        }

        canonical::decode_object(&body)?;
    } else if request_body_policy != "must_be_empty" || !body.is_empty() {
        return Err(BrokerError::denied("broker request body policy is invalid"));
    }

    Ok(body)
}

#[must_use]
pub fn commitment_material(domain: &str, value: &[u8]) -> Vec<u8> {
    let mut material =
        format!("cieplik206.fakturownia.live-effect-commitment\0{WIRE_VERSION}\0{domain}\0")
            .into_bytes();
    material.extend_from_slice(value);
    material
}

fn verify_commitment(key: &[u8], material: &[u8], expected: &str) -> BrokerResult<()> {
    let actual = hmac_sha256_hex(key, material)?;

    if !constant_time_hex_equal(expected, &actual) {
        return Err(BrokerError::denied(
            "broker effect commitment does not match",
        ));
    }

    Ok(())
}

fn validate_target_origin(value: &str) -> BrokerResult<()> {
    let url =
        Url::parse(value).map_err(|_| BrokerError::denied("broker target origin is invalid"))?;

    if url.scheme() != "https"
        || url.username() != ""
        || url.password().is_some()
        || url.port().is_some()
        || url.path() != "/"
        || url.query().is_some()
        || url.fragment().is_some()
        || url.host_str().is_none()
        || value.ends_with('/')
    {
        return Err(BrokerError::denied(
            "broker target origin must be one exact HTTPS origin",
        ));
    }

    Ok(())
}

fn valid_ksef_path(value: &str) -> bool {
    let Ok(url) = Url::parse(&format!("https://sealed.invalid{value}")) else {
        return false;
    };

    let Some(invoice_id) = invoice_id_from_path(url.path(), ".json") else {
        return false;
    };
    let query = url.query_pairs().collect::<Vec<_>>();

    let send_only = query.len() == 1 && query[0].0 == "send_to_ksef" && query[0].1 == "yes";
    let send_with_fields = query.len() == 2
        && query[0].0 == "fields[invoice]"
        && query[0].1 == "id,gov_status,gov_id,gov_error_messages"
        && query[1].0 == "send_to_ksef"
        && query[1].1 == "yes";

    if !send_only && !send_with_fields {
        return false;
    }

    canonical_decimal(invoice_id)
}

fn valid_invoice_search_path(value: &str, include_positions: bool) -> bool {
    let Ok(url) = Url::parse(&format!("https://sealed.invalid{value}")) else {
        return false;
    };

    if url.path() != "/invoices.json" || url.fragment().is_some() {
        return false;
    }

    let query = url
        .query_pairs()
        .collect::<std::collections::BTreeMap<_, _>>();
    let expected_length = if include_positions { 5 } else { 4 };

    if query.len() != expected_length
        || query.get("period").map(|value| value.as_ref()) != Some("all")
        || query.get("per_page").map(|value| value.as_ref()) != Some("100")
        || !query.get("page").is_some_and(|value| {
            value
                .parse::<u32>()
                .is_ok_and(|page| (1..=100).contains(&page))
        })
        || !query.get("oid").is_some_and(|value| {
            (4..=191).contains(&value.len())
                && value
                    .bytes()
                    .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'_' | b'.'))
        })
    {
        return false;
    }

    !include_positions || query.get("include_positions").map(|value| value.as_ref()) == Some("true")
}

fn valid_invoice_read_path(value: &str) -> bool {
    let Ok(url) = Url::parse(&format!("https://sealed.invalid{value}")) else {
        return false;
    };
    let Some(invoice_id) = invoice_id_from_path(url.path(), ".json") else {
        return false;
    };
    let query = url.query_pairs().collect::<Vec<_>>();

    canonical_decimal(invoice_id)
        && query.len() == 1
        && query[0].0 == "fields[invoice]"
        && query[0].1 == "id,gov_status,gov_id,gov_error_messages"
}

fn valid_invoice_pdf_path(value: &str) -> bool {
    let Ok(url) = Url::parse(&format!("https://sealed.invalid{value}")) else {
        return false;
    };

    url.query().is_none() && invoice_id_from_path(url.path(), ".pdf").is_some_and(canonical_decimal)
}

fn invoice_id_from_path<'a>(path: &'a str, suffix: &str) -> Option<&'a str> {
    path.strip_prefix("/invoices/")
        .and_then(|value| value.strip_suffix(suffix))
}

fn canonical_decimal(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 19
        && !value.starts_with('0')
        && value.bytes().all(|byte| byte.is_ascii_digit())
}

fn is_lower_hex(value: &str, exact_length: usize) -> bool {
    value.len() == exact_length
        && value
            .bytes()
            .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase())
}

fn strict_utc_microsecond(value: &str) -> BrokerResult<OffsetDateTime> {
    if value.len() != 27
        || value.as_bytes().get(4) != Some(&b'-')
        || value.as_bytes().get(7) != Some(&b'-')
        || value.as_bytes().get(10) != Some(&b'T')
        || value.as_bytes().get(13) != Some(&b':')
        || value.as_bytes().get(16) != Some(&b':')
        || value.as_bytes().get(19) != Some(&b'.')
        || value.as_bytes().get(26) != Some(&b'Z')
    {
        return Err(BrokerError::denied(
            "broker timestamp is not a strict UTC microsecond instant",
        ));
    }

    OffsetDateTime::parse(value, &Rfc3339).map_err(|_| {
        BrokerError::denied("broker timestamp is not a strict UTC microsecond instant")
    })
}

#[cfg(test)]
mod tests {
    use base64::Engine;
    use base64::engine::general_purpose::STANDARD;

    use crate::crypto::hmac_sha256_hex;

    use super::{
        ConcurrentEffectExecutionProposal, EffectExecutionProposal, EffectExecutionRequest,
        LiveEffectDescriptor, ReadObservationProposal, commitment_material,
    };

    #[test]
    fn matches_the_php_effect_proposal_golden_vector() -> Result<(), Box<dyn std::error::Error>> {
        let proposal = EffectExecutionProposal {
            contract: EffectExecutionProposal::CONTRACT.to_owned(),
            version: "1".to_owned(),
            evidence_contract: "fakturownia-invoice-identity-s0.3-v1".to_owned(),
            effect_id: "6".repeat(32),
            effect_sequence: 1,
            profile: "invoice_identity".to_owned(),
            target_key: "primary".to_owned(),
            capability: "invoice.vat.issue".to_owned(),
            semantic_effect: "invoice_create".to_owned(),
            http_method: "POST".to_owned(),
            endpoint_template: "/invoices.json".to_owned(),
            provider_path: "/invoices.json".to_owned(),
            request_body_base64: STANDARD.encode(br#"{"invoice":{"kind":"vat"}}"#),
            connect_timeout_ms: 1_000,
            request_timeout_ms: 5_000,
            maximum_response_bytes: 1_048_576,
        };

        assert_eq!(
            crate::crypto::sha256_hex(&crate::canonical::encode(&proposal)?),
            "0ba5e1848069aac582a0bc3bc98e0d4cb9cfbc1d956e0d1c5e92dbf7286ebaf1",
        );
        assert_eq!(
            proposal.validate("fakturownia-invoice-identity-s0.3-v1")?.0,
            br#"{"invoice":{"kind":"vat"}}"#,
        );

        Ok(())
    }

    fn request() -> EffectExecutionRequest {
        let key = [7_u8; 32];
        let body = br#"{"invoice":{"kind":"vat"}}"#;
        let target = "https://demo.fakturownia.pl";
        let path = "/invoices.json";

        EffectExecutionRequest {
            contract: EffectExecutionRequest::CONTRACT.to_owned(),
            version: "1".to_owned(),
            descriptor: LiveEffectDescriptor {
                contract: LiveEffectDescriptor::CONTRACT.to_owned(),
                version: "1".to_owned(),
                evidence_contract: "fakturownia-invoice-identity-s0.3-v1".to_owned(),
                run_id: "1".repeat(32),
                effect_id: "2".repeat(32),
                effect_sequence: 1,
                profile: "invoice_identity".to_owned(),
                target_key: "primary".to_owned(),
                capability: "invoice.vat.issue".to_owned(),
                semantic_effect: "invoice_create".to_owned(),
                http_method: "POST".to_owned(),
                endpoint_template: "/invoices.json".to_owned(),
                commitment_scheme: "hmac-sha256-ephemeral-run-key-v1".to_owned(),
                target_origin_hmac_sha256: hmac_sha256_hex(
                    &key,
                    &commitment_material("target", target.as_bytes()),
                )
                .unwrap_or_default(),
                operation_identity_hmac_sha256: hmac_sha256_hex(
                    &key,
                    &commitment_material("operation", format!("POST\n{path}").as_bytes()),
                )
                .unwrap_or_default(),
                request_body_hmac_sha256: hmac_sha256_hex(
                    &key,
                    &commitment_material("request-body", body),
                )
                .unwrap_or_default(),
                request_body_size_bytes: body.len(),
                request_body_policy: "required_non_empty".to_owned(),
                launch_manifest_sha256: "3".repeat(64),
                supervisor_attestation_sha256: "4".repeat(64),
                broker_policy_sha256: "5".repeat(64),
                authorization_set_sha256: "6".repeat(64),
                authorization_bundle_sha256: "7".repeat(64),
                probe_plan_sha256: "8".repeat(64),
                claim_request_sha256: "9".repeat(64),
                consumption_receipt_sha256: "a".repeat(64),
                claim_nonce: STANDARD.encode([9_u8; 32]),
                run_started_at: "2026-08-28T10:00:00.000000Z".to_owned(),
                connect_timeout_ms: 1_000,
                request_timeout_ms: 5_000,
                maximum_response_bytes: 1_048_576,
            },
            provider_path: path.to_owned(),
            request_body_base64: STANDARD.encode(body),
        }
    }

    #[test]
    fn validates_the_complete_effect_commitment() -> Result<(), Box<dyn std::error::Error>> {
        let request = request();

        assert_eq!(
            request.validate("https://demo.fakturownia.pl", &[7_u8; 32])?,
            br#"{"invoice":{"kind":"vat"}}"#,
        );

        Ok(())
    }

    #[test]
    fn rejects_path_body_and_target_substitution() {
        let mut path = request();
        path.provider_path = "/clients.json".to_owned();
        let mut body = request();
        body.request_body_base64 = STANDARD.encode(br#"{"invoice":{"kind":"proforma"}}"#);
        let target = request();

        assert!(
            path.validate("https://demo.fakturownia.pl", &[7_u8; 32])
                .is_err()
        );
        assert!(
            body.validate("https://demo.fakturownia.pl", &[7_u8; 32])
                .is_err()
        );
        assert!(
            target
                .validate("https://attacker.invalid", &[7_u8; 32])
                .is_err()
        );
    }

    #[test]
    fn allowlists_only_the_exact_read_paths_and_same_oid_batch()
    -> Result<(), Box<dyn std::error::Error>> {
        let read = ReadObservationProposal {
            contract: ReadObservationProposal::CONTRACT.to_owned(),
            version: "1".to_owned(),
            evidence_contract: "fakturownia-invoice-identity-s0.3-v1".to_owned(),
            observation_id: "1".repeat(32),
            profile: "invoice_identity".to_owned(),
            target_key: "primary".to_owned(),
            capability: "invoice.search".to_owned(),
            http_method: "GET".to_owned(),
            endpoint_template: "/invoices.json".to_owned(),
            provider_path:
                "/invoices.json?include_positions=true&oid=s03-run-exact&page=1&per_page=100&period=all"
                    .to_owned(),
            connect_timeout_ms: 1_000,
            request_timeout_ms: 5_000,
            maximum_response_bytes: 1_048_576,
        };
        read.validate("fakturownia-invoice-identity-s0.3-v1")?;
        let mut token_injected = read.clone();
        token_injected.provider_path.push_str("&api_token=secret");
        assert!(
            token_injected
                .validate("fakturownia-invoice-identity-s0.3-v1")
                .is_err()
        );

        let first = EffectExecutionProposal {
            contract: EffectExecutionProposal::CONTRACT.to_owned(),
            version: "1".to_owned(),
            evidence_contract: "fakturownia-invoice-identity-s0.3-v1".to_owned(),
            effect_id: "2".repeat(32),
            effect_sequence: 1,
            profile: "invoice_identity".to_owned(),
            target_key: "primary".to_owned(),
            capability: "invoice.vat.issue".to_owned(),
            semantic_effect: "invoice_create".to_owned(),
            http_method: "POST".to_owned(),
            endpoint_template: "/invoices.json".to_owned(),
            provider_path: "/invoices.json".to_owned(),
            request_body_base64: STANDARD.encode(br#"{"invoice":{"oid":"same"}}"#),
            connect_timeout_ms: 1_000,
            request_timeout_ms: 5_000,
            maximum_response_bytes: 1_048_576,
        };
        let mut second = first.clone();
        second.effect_id = "3".repeat(32);
        second.effect_sequence = 2;
        let batch = ConcurrentEffectExecutionProposal {
            contract: ConcurrentEffectExecutionProposal::CONTRACT.to_owned(),
            version: "1".to_owned(),
            proposals: vec![first, second],
        };
        batch.validate("fakturownia-invoice-identity-s0.3-v1")?;

        Ok(())
    }
}
