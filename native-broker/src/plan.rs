use std::collections::{BTreeMap, BTreeSet};

use serde::{Deserialize, Serialize};
use serde_json::{Map, Value, json};
use url::Url;
use zeroize::Zeroizing;

use crate::authorization::{AuthorizationBundle, SignedLiveProbeAuthorizationEnvelope};
use crate::broker::{ProviderCredential, ProviderCredentialSet};
use crate::canonical;
use crate::crypto::{
    constant_time_hex_equal, decode_canonical_base64, hmac_sha256_hex, sha256_hex,
};
use crate::trust::validate_sha256;
use crate::{BrokerError, BrokerResult};

const INVOICE_IDENTITY_EVIDENCE: &str = "fakturownia-invoice-identity-s0.3-v1";
const KSEF_DEMO_EVIDENCE: &str = "fakturownia-ksef-demo-s0.4-v1";

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(tag = "evidence_contract")]
pub enum NativeProbePlan {
    #[serde(rename = "fakturownia-invoice-identity-s0.3-v1")]
    InvoiceIdentity(InvoiceIdentityProbePlan),
    #[serde(rename = "fakturownia-ksef-demo-s0.4-v1")]
    KsefDemo(KsefDemoProbePlan),
}

impl NativeProbePlan {
    pub const CONTRACT: &'static str = "cieplik206.fakturownia.native-probe-plan";

    pub fn verify(
        &self,
        bundle: &AuthorizationBundle,
        credentials: &ProviderCredentialSet,
    ) -> BrokerResult<()> {
        credentials.validate_set()?;

        match self {
            Self::InvoiceIdentity(plan) => plan.verify(bundle, credentials),
            Self::KsefDemo(plan) => plan.verify(bundle, credentials),
        }
    }

    pub fn sha256(&self) -> BrokerResult<String> {
        Ok(sha256_hex(&canonical::encode(self)?))
    }

    pub fn evidence_contract(&self) -> &'static str {
        match self {
            Self::InvoiceIdentity(_) => INVOICE_IDENTITY_EVIDENCE,
            Self::KsefDemo(_) => KSEF_DEMO_EVIDENCE,
        }
    }
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct InvoiceIdentityProbePlan {
    pub contract: String,
    pub version: String,
    pub environment: String,
    pub limits: InvoiceIdentityLimits,
    pub targets: Vec<InvoiceIdentityTarget>,
    pub payload: Map<String, Value>,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct InvoiceIdentityLimits {
    pub visibility_window_ms: u32,
    pub poll_interval_ms: u32,
    pub max_search_pages: u32,
    pub lost_response_timeout_ms: u32,
    pub connect_timeout_ms: u32,
    pub request_timeout_ms: u32,
    pub write_attempt_budget: u32,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct InvoiceIdentityTarget {
    pub target_key: String,
    pub expected_account_fingerprint: String,
}

impl InvoiceIdentityProbePlan {
    fn verify(
        &self,
        bundle: &AuthorizationBundle,
        credentials: &ProviderCredentialSet,
    ) -> BrokerResult<()> {
        if self.contract != NativeProbePlan::CONTRACT
            || self.version != "1"
            || bundle.evidence_contract != INVOICE_IDENTITY_EVIDENCE
            || !matches!(self.environment.as_str(), "demo_pl" | "demo_regional")
            || self.targets.len() != 2
            || self.targets[0].target_key != "primary"
            || self.targets[1].target_key != "secondary"
            || self.limits.write_attempt_budget != 11
            || self.limits.visibility_window_ms < 10_000
            || self.limits.visibility_window_ms > 120_000
            || self.limits.poll_interval_ms < 100
            || self.limits.poll_interval_ms > 1_000
            || self.limits.poll_interval_ms > self.limits.visibility_window_ms
            || self.limits.max_search_pages < 10
            || self.limits.max_search_pages > 100
            || self.limits.lost_response_timeout_ms < 1_000
            || self.limits.lost_response_timeout_ms > 10_000
            || self.limits.connect_timeout_ms < 1_000
            || self.limits.connect_timeout_ms > 10_000
            || self.limits.request_timeout_ms < 10_000
            || self.limits.request_timeout_ms > 60_000
        {
            return Err(BrokerError::denied(
                "native S0.3 probe plan contract is invalid",
            ));
        }

        validate_invoice_identity_payload(&self.payload)?;
        let authorizations = authorizations_by_profile(bundle)?;
        let authorization = authorizations.get("invoice_identity").ok_or_else(|| {
            BrokerError::denied("native S0.3 authorization profile is unavailable")
        })?;
        let primary = credential(credentials, "invoice_identity", "primary")?;
        let secondary = credential(credentials, "invoice_identity", "secondary")?;

        if credentials.targets.len() != 2
            || primary.environment != self.environment
            || secondary.environment != self.environment
            || primary.expected_account_fingerprint != self.targets[0].expected_account_fingerprint
            || secondary.expected_account_fingerprint
                != self.targets[1].expected_account_fingerprint
        {
            return Err(BrokerError::denied(
                "native S0.3 credential targets do not match the probe plan",
            ));
        }

        let primary_key = binding_key(primary)?;
        let secondary_key = binding_key(secondary)?;

        if primary_key.as_slice() != secondary_key.as_slice() {
            return Err(BrokerError::denied(
                "native S0.3 target credentials do not share one authorization binding",
            ));
        }

        let limits = serde_json::to_value(&self.limits)
            .map_err(|_| BrokerError::denied("cannot encode native S0.3 limits"))?;
        let payload = Value::Object(self.payload.clone());
        let safety = self
            .payload
            .get("safety")
            .cloned()
            .ok_or_else(|| BrokerError::denied("native S0.3 safety plan is unavailable"))?;
        let templates = json!({
            "invoice": self.payload.get("invoice"),
            "secondary_account_invoice": self.payload.get("secondary_account_invoice"),
            "correction_invoice": self.payload.get("correction_invoice"),
        });
        let tenant = json!({
            "primary": {"environment": self.environment, "base_url": primary.target_origin},
            "secondary": {"environment": self.environment, "base_url": secondary.target_origin},
        });
        let accounts = json!({
            "identity_basis": "environment_account_id",
            "primary_account_fingerprint": self.targets[0].expected_account_fingerprint,
            "secondary_account_fingerprint": self.targets[1].expected_account_fingerprint,
        });
        let profile = json!({
            "primary_token": primary.api_token,
            "secondary_token": secondary.api_token,
            "payload": payload,
            "limits": limits,
        });
        let policy = json!({
            "identity_field": "oid",
            "remote_uniqueness_flag": "oid_unique=yes",
            "lost_response_blind_retry": false,
            "visibility": "full_window_with_final_exact_boundary",
            "required_environment_fixtures": ["demo_pl", "demo_regional"],
        });
        let expected_target = json!({
            "environment": self.environment,
            "profile": "invoice_identity",
            "tenant_hmac_sha256": hmac_json(&primary_key, &tenant)?,
            "account_hmac_sha256": hmac_json(&primary_key, &accounts)?,
        });
        let expected_commitments = json!({
            "scheme": "hmac-sha256-ephemeral-run-key-v1",
            "configuration_hmac_sha256": hmac_json(&primary_key, &profile)?,
            "policy_hmac_sha256": hmac_json(&primary_key, &policy)?,
            "safety_hmac_sha256": hmac_json(&primary_key, &safety)?,
            "templates_hmac_sha256": hmac_json(&primary_key, &templates)?,
        });
        let expected_challenge =
            canonical_base64_hmac(&primary_key, b"s0.3-authorization-challenge")?;
        let expected_run_id =
            hmac_sha256_hex(&primary_key, b"s0.3-authorization-run")?[..32].to_owned();

        verify_authorization_domain(
            authorization,
            INVOICE_IDENTITY_EVIDENCE,
            &expected_challenge,
            &expected_target,
            &expected_commitments,
            &limits,
            &expected_run_id,
        )
    }
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct KsefDemoProbePlan {
    pub contract: String,
    pub version: String,
    pub environment: String,
    pub limits: KsefDemoLimits,
    pub targets: Vec<KsefDemoTarget>,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct KsefDemoLimits {
    pub poll_window_ms: u32,
    pub poll_interval_ms: u32,
    pub max_search_pages: u32,
    pub pre_send_observation_window_ms: u32,
    pub visibility_window_ms: u32,
    pub visibility_poll_interval_ms: u32,
    pub connect_timeout_ms: u32,
    pub request_timeout_ms: u32,
    pub minimum_pdf_size_bytes: u32,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct KsefDemoTarget {
    pub profile: String,
    pub target_key: String,
    pub expected_account_fingerprint: String,
    pub ownership: String,
    pub validation_mode: String,
    pub expected_validation_field: String,
    pub ksef_environment: String,
    pub gov_auto_send_mode: Option<String>,
    pub validate_invoices_for_gov: bool,
    pub buyer_company: bool,
    pub throwaway_tenant: bool,
    pub email_delivery_disabled: bool,
    pub payments_disabled: bool,
    pub webhooks_disabled: bool,
    pub settings_checksum: String,
    pub valid_invoice: Map<String, Value>,
    pub invalid_invoice: Map<String, Value>,
}

impl KsefDemoProbePlan {
    fn verify(
        &self,
        bundle: &AuthorizationBundle,
        credentials: &ProviderCredentialSet,
    ) -> BrokerResult<()> {
        let expected_profiles = [
            "explicit_block",
            "explicit_persist",
            "auto_block",
            "auto_persist",
        ];

        if self.contract != NativeProbePlan::CONTRACT
            || self.version != "1"
            || self.environment != "ksef_demo"
            || bundle.evidence_contract != KSEF_DEMO_EVIDENCE
            || self.targets.len() != expected_profiles.len()
            || self
                .targets
                .iter()
                .map(|target| target.profile.as_str())
                .ne(expected_profiles)
            || credentials.targets.len() != expected_profiles.len()
        {
            return Err(BrokerError::denied(
                "native S0.4 probe plan contract is invalid",
            ));
        }

        validate_ksef_limits(&self.limits)?;
        let authorizations = authorizations_by_profile(bundle)?;
        let limits = serde_json::to_value(&self.limits)
            .map_err(|_| BrokerError::denied("cannot encode native S0.4 limits"))?;

        for target in &self.targets {
            target.validate()?;
            let authorization = authorizations.get(target.profile.as_str()).ok_or_else(|| {
                BrokerError::denied("native S0.4 authorization profile is unavailable")
            })?;
            let credential = credential(credentials, &target.profile, &target.target_key)?;

            if credential.environment != self.environment
                || credential.expected_account_fingerprint != target.expected_account_fingerprint
            {
                return Err(BrokerError::denied(
                    "native S0.4 credential target does not match the probe plan",
                ));
            }

            let binding_key = binding_key(credential)?;
            let host = Url::parse(&credential.target_origin)
                .ok()
                .and_then(|url| url.host_str().map(str::to_owned))
                .ok_or_else(|| BrokerError::denied("native S0.4 credential host is invalid"))?;
            let settings_checksum = ksef_settings_checksum(target, &host, authorization)?;

            if !constant_time_hex_equal(&target.settings_checksum, &settings_checksum) {
                return Err(BrokerError::denied(
                    "native S0.4 settings checksum does not bind the target",
                ));
            }

            let expected_target = json!({
                "environment": "ksef_demo",
                "profile": target.profile,
                "tenant_hmac_sha256": hmac_json(&binding_key, &json!({
                    "profile_key": target.profile,
                    "host": host,
                }))?,
                "account_hmac_sha256": hmac_json(&binding_key, &json!({
                    "profile_key": target.profile,
                    "account_fingerprint": target.expected_account_fingerprint,
                }))?,
            });
            let expected_commitments = json!({
                "scheme": "hmac-sha256-ephemeral-run-key-v1",
                "configuration_hmac_sha256": hmac_json(&binding_key, &json!({
                    "base_url": credential.target_origin,
                    "token": credential.api_token,
                    "settings_checksum": target.settings_checksum,
                    "limits": limits,
                }))?,
                "policy_hmac_sha256": hmac_json(&binding_key, &json!({
                    "ownership": target.ownership,
                    "validation_mode": target.validation_mode,
                    "ksef_environment": target.ksef_environment,
                    "gov_auto_send_mode": target.gov_auto_send_mode,
                    "validate_invoices_for_gov": target.validate_invoices_for_gov,
                    "buyer_company": target.buyer_company,
                    "expected_validation_field": target.expected_validation_field,
                    "issue_ksef_behavior": "never_send",
                    "ensure_accepted": "separate_operation",
                }))?,
                "safety_hmac_sha256": hmac_json(&binding_key, &json!({
                    "throwaway_tenant": target.throwaway_tenant,
                    "email_delivery_disabled": target.email_delivery_disabled,
                    "payments_disabled": target.payments_disabled,
                    "webhooks_disabled": target.webhooks_disabled,
                }))?,
                "templates_hmac_sha256": hmac_json(&binding_key, &json!({
                    "valid_invoice": target.valid_invoice,
                    "invalid_invoice": target.invalid_invoice,
                }))?,
            });
            let expected_challenge = canonical_base64_hmac(
                &binding_key,
                format!(
                    "fakturownia-s0.4-{}-authorization-challenge",
                    target.profile
                )
                .as_bytes(),
            )?;

            verify_authorization_domain(
                authorization,
                KSEF_DEMO_EVIDENCE,
                &expected_challenge,
                &expected_target,
                &expected_commitments,
                &limits,
                &bundle.run_id,
            )?;
        }

        Ok(())
    }
}

impl KsefDemoTarget {
    fn validate(&self) -> BrokerResult<()> {
        validate_sha256(&self.expected_account_fingerprint)?;
        validate_sha256(&self.settings_checksum)?;

        let expected = match self.profile.as_str() {
            "explicit_block" => ("explicit_sdk", "block_invalid", None, true),
            "explicit_persist" => ("explicit_sdk", "persist_with_errors", None, false),
            "auto_block" => (
                "provider_auto_send",
                "block_invalid",
                Some("pl_companies"),
                true,
            ),
            "auto_persist" => (
                "provider_auto_send",
                "persist_with_errors",
                Some("pl_companies"),
                false,
            ),
            _ => return Err(BrokerError::denied("native S0.4 profile is invalid")),
        };

        if self.target_key != self.profile
            || self.ownership != expected.0
            || self.validation_mode != expected.1
            || self.gov_auto_send_mode.as_deref() != expected.2
            || self.validate_invoices_for_gov != expected.3
            || self.expected_validation_field != "buyer_tax_no"
            || self.ksef_environment != "demo"
            || !self.buyer_company
            || !self.throwaway_tenant
            || !self.email_delivery_disabled
            || !self.payments_disabled
            || !self.webhooks_disabled
            || self.valid_invoice.is_empty()
            || self.invalid_invoice.is_empty()
        {
            return Err(BrokerError::denied("native S0.4 profile policy is invalid"));
        }

        Ok(())
    }
}

fn validate_invoice_identity_payload(payload: &Map<String, Value>) -> BrokerResult<()> {
    let keys = payload.keys().map(String::as_str).collect::<BTreeSet<_>>();
    let required = [
        "correction_invoice",
        "invoice",
        "safety",
        "secondary_account_invoice",
        "secondary_department_id",
    ]
    .into_iter()
    .collect::<BTreeSet<_>>();
    let safety = payload.get("safety").and_then(Value::as_object);

    if keys != required
        || !matches!(payload.get("invoice"), Some(Value::Object(_)))
        || !matches!(
            payload.get("secondary_account_invoice"),
            Some(Value::Object(_))
        )
        || !matches!(payload.get("correction_invoice"), Some(Value::Object(_)))
        || safety.and_then(|value| value.get("throwaway_tenants")) != Some(&Value::Bool(true))
        || safety.and_then(|value| value.get("ksef_auto_send_disabled")) != Some(&Value::Bool(true))
        || safety.and_then(|value| value.get("email_delivery_disabled")) != Some(&Value::Bool(true))
    {
        return Err(BrokerError::denied("native S0.3 payload plan is invalid"));
    }

    Ok(())
}

fn validate_ksef_limits(limits: &KsefDemoLimits) -> BrokerResult<()> {
    if limits.poll_window_ms == 0
        || limits.poll_window_ms > 120_000
        || limits.poll_interval_ms < 100
        || limits.poll_interval_ms > limits.poll_window_ms
        || limits.max_search_pages == 0
        || limits.max_search_pages > 50
        || limits.pre_send_observation_window_ms == 0
        || limits.pre_send_observation_window_ms > 30_000
        || limits.poll_interval_ms > limits.pre_send_observation_window_ms
        || limits.visibility_window_ms == 0
        || limits.visibility_window_ms > 60_000
        || limits.visibility_poll_interval_ms < 100
        || limits.visibility_poll_interval_ms > limits.visibility_window_ms
        || limits.connect_timeout_ms == 0
        || limits.connect_timeout_ms > 10_000
        || limits.request_timeout_ms == 0
        || limits.request_timeout_ms > 60_000
        || limits.minimum_pdf_size_bytes < 1_024
        || limits.minimum_pdf_size_bytes > 25 * 1_024 * 1_024
    {
        return Err(BrokerError::denied("native S0.4 limits are invalid"));
    }

    Ok(())
}

fn authorizations_by_profile(
    bundle: &AuthorizationBundle,
) -> BrokerResult<BTreeMap<&str, &SignedLiveProbeAuthorizationEnvelope>> {
    let mut authorizations = BTreeMap::new();

    for document in &bundle.authorizations {
        let authorization = &document.envelope;

        if authorizations
            .insert(authorization.target.profile.as_str(), authorization)
            .is_some()
        {
            return Err(BrokerError::denied(
                "native probe plan contains a duplicate authorization profile",
            ));
        }
    }

    Ok(authorizations)
}

fn credential<'a>(
    credentials: &'a ProviderCredentialSet,
    profile: &str,
    target_key: &str,
) -> BrokerResult<&'a ProviderCredential> {
    credentials.select(profile, target_key)
}

fn binding_key(credential: &ProviderCredential) -> BrokerResult<Zeroizing<Vec<u8>>> {
    Ok(Zeroizing::new(decode_canonical_base64(
        &credential.authorization_binding_key_base64,
        32,
    )?))
}

fn hmac_json(key: &[u8], value: &Value) -> BrokerResult<String> {
    hmac_sha256_hex(key, &canonical::encode(value)?)
}

fn canonical_base64_hmac(key: &[u8], value: &[u8]) -> BrokerResult<String> {
    use base64::Engine;
    use base64::engine::general_purpose::STANDARD;
    use hmac::{Hmac, Mac};
    use sha2::Sha256;

    let mut hmac = Hmac::<Sha256>::new_from_slice(key)
        .map_err(|_| BrokerError::denied("native probe plan HMAC key is invalid"))?;
    hmac.update(value);

    Ok(STANDARD.encode(hmac.finalize().into_bytes()))
}

#[allow(clippy::too_many_arguments)]
fn verify_authorization_domain(
    authorization: &SignedLiveProbeAuthorizationEnvelope,
    evidence_contract: &str,
    expected_challenge: &str,
    expected_target: &Value,
    expected_commitments: &Value,
    expected_limits: &Value,
    expected_run_id: &str,
) -> BrokerResult<()> {
    let actual_target = serde_json::to_value(&authorization.target)
        .map_err(|_| BrokerError::denied("cannot encode native authorization target"))?;
    let actual_commitments = serde_json::to_value(&authorization.commitments)
        .map_err(|_| BrokerError::denied("cannot encode native authorization commitments"))?;
    let actual_limits = Value::Object(authorization.limits.clone());

    if authorization.evidence_contract != evidence_contract
        || authorization.challenge != expected_challenge
        || authorization.consumption.run_id != expected_run_id
        || canonical::encode(&actual_target)? != canonical::encode(expected_target)?
        || canonical::encode(&actual_commitments)? != canonical::encode(expected_commitments)?
        || canonical::encode(&actual_limits)? != canonical::encode(expected_limits)?
    {
        return Err(BrokerError::denied(
            "native probe plan does not match its signed operator authorization",
        ));
    }

    Ok(())
}

fn ksef_settings_checksum(
    target: &KsefDemoTarget,
    host: &str,
    authorization: &SignedLiveProbeAuthorizationEnvelope,
) -> BrokerResult<String> {
    let mut settings = Map::new();
    settings.insert(
        "profile_key".to_owned(),
        Value::String(target.profile.clone()),
    );
    settings.insert("tenant_host".to_owned(), Value::String(host.to_lowercase()));
    settings.insert(
        "tenant_fingerprint".to_owned(),
        Value::String(target.expected_account_fingerprint.clone()),
    );
    settings.insert(
        "ksef_environment".to_owned(),
        Value::String(target.ksef_environment.clone()),
    );
    settings.insert(
        "ownership".to_owned(),
        Value::String(target.ownership.clone()),
    );
    settings.insert(
        "validation_mode".to_owned(),
        Value::String(target.validation_mode.clone()),
    );
    settings.insert(
        "gov_auto_send_mode".to_owned(),
        target
            .gov_auto_send_mode
            .as_ref()
            .map_or(Value::Null, |value| Value::String(value.clone())),
    );
    settings.insert(
        "validate_invoices_for_gov".to_owned(),
        Value::Bool(target.validate_invoices_for_gov),
    );
    settings.insert(
        "buyer_company".to_owned(),
        Value::Bool(target.buyer_company),
    );
    settings.insert(
        "throwaway_tenant".to_owned(),
        Value::Bool(target.throwaway_tenant),
    );
    settings.insert(
        "email_delivery_disabled".to_owned(),
        Value::Bool(target.email_delivery_disabled),
    );
    settings.insert(
        "payments_disabled".to_owned(),
        Value::Bool(target.payments_disabled),
    );
    settings.insert(
        "webhooks_disabled".to_owned(),
        Value::Bool(target.webhooks_disabled),
    );
    settings.insert(
        "operator_attested_at".to_owned(),
        Value::String(rfc3339_seconds(&authorization.issued_at)?),
    );
    settings.insert(
        "operator_attestation_expires_at".to_owned(),
        Value::String(rfc3339_seconds(&authorization.expires_at)?),
    );
    let encoded = serde_json::to_string(&settings)
        .map_err(|_| BrokerError::denied("cannot encode native S0.4 settings"))?;

    Ok(sha256_hex(
        format!("fakturownia-s0.4-settings|{encoded}").as_bytes(),
    ))
}

fn rfc3339_seconds(value: &str) -> BrokerResult<String> {
    if value.len() != 27 || !value.ends_with('Z') {
        return Err(BrokerError::denied(
            "native S0.4 authorization instant is invalid",
        ));
    }

    Ok(format!("{}+00:00", &value[..19]))
}
