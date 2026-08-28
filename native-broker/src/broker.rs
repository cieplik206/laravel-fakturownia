use std::path::{Path, PathBuf};
#[cfg(feature = "network")]
use std::time::Duration as StdDuration;

use ed25519_dalek::SigningKey;
use serde::{Deserialize, Serialize};
use time::OffsetDateTime;
use url::Url;
use zeroize::{Zeroize, ZeroizeOnDrop};

use crate::canonical;
use crate::cas;
use crate::crypto::{constant_time_hex_equal, hmac_sha256_hex, sha256_hex, verify_base64};
use crate::observation::{
    BrokeredReadObservationResponse, BrokeredReadObservationResultEnvelope,
    ReadExecutionObservation,
};
use crate::protocol::{
    COMMITMENT_SCHEME, ConcurrentEffectExecutionProposal, EffectExecutionProposal,
    EffectExecutionRequest, LiveEffectDescriptor, ReadObservationProposal, commitment_material,
};
use crate::result::{
    BrokeredEffectExecutionReceiptEnvelope, BrokeredEffectExecutionResultEnvelope,
    ExecutionObservation,
};
use crate::trust::{
    NativeBrokerTrustPolicyEnvelope, NativeSupervisorAttestationEnvelope, SignedDocument,
    format_utc_microsecond,
};
use crate::{BrokerError, BrokerResult};

#[derive(Deserialize, Zeroize, ZeroizeOnDrop)]
#[serde(deny_unknown_fields)]
pub struct ProviderCredential {
    pub contract: String,
    pub version: String,
    pub environment: String,
    pub profile: String,
    pub target_key: String,
    pub target_origin: String,
    pub api_token: String,
    pub expected_account_fingerprint: String,
    pub authorization_binding_key_base64: String,
    pub commitment_key_base64: String,
    pub provider_request_id_hmac_key_base64: String,
}

impl ProviderCredential {
    pub const CONTRACT: &'static str = "cieplik206.fakturownia.native-broker-credential";

    pub fn validate(
        &self,
        expected_profile: &str,
        expected_target_key: &str,
    ) -> BrokerResult<(Vec<u8>, Vec<u8>)> {
        if self.contract != Self::CONTRACT
            || self.version != "1"
            || self.profile != expected_profile
            || self.target_key != expected_target_key
            || !matches!(
                self.environment.as_str(),
                "demo_pl" | "demo_regional" | "ksef_demo"
            )
            || self.api_token.is_empty()
            || self.api_token.len() > 4_096
        {
            return Err(BrokerError::denied(
                "native broker credential contract is invalid",
            ));
        }

        let target = Url::parse(&self.target_origin)
            .map_err(|_| BrokerError::denied("native broker target is invalid"))?;

        if target.scheme() != "https"
            || target.username() != ""
            || target.password().is_some()
            || target.port().is_some()
            || target.path() != "/"
            || target.query().is_some()
            || target.fragment().is_some()
            || target.host_str().is_none()
            || self.target_origin.ends_with('/')
        {
            return Err(BrokerError::denied(
                "native broker target must be one exact HTTPS origin",
            ));
        }

        let commitment_key =
            crate::crypto::decode_canonical_base64(&self.commitment_key_base64, 32)?;
        let request_id_key =
            crate::crypto::decode_canonical_base64(&self.provider_request_id_hmac_key_base64, 32)?;
        crate::crypto::decode_canonical_base64(&self.authorization_binding_key_base64, 32)?;
        crate::trust::validate_sha256(&self.expected_account_fingerprint)?;

        Ok((commitment_key, request_id_key))
    }
}

#[derive(Deserialize, Zeroize, ZeroizeOnDrop)]
#[serde(deny_unknown_fields)]
pub struct ProviderCredentialSet {
    pub contract: String,
    pub version: String,
    pub targets: Vec<ProviderCredential>,
}

impl ProviderCredentialSet {
    pub const CONTRACT: &'static str = "cieplik206.fakturownia.native-broker-credential-set";

    pub fn select(&self, profile: &str, target_key: &str) -> BrokerResult<&ProviderCredential> {
        self.validate_set()?;

        let credential = self
            .targets
            .iter()
            .find(|credential| credential.target_key == target_key)
            .ok_or_else(|| BrokerError::denied("native broker target credential is unavailable"))?;
        credential.validate(profile, target_key)?;

        Ok(credential)
    }

    pub fn validate_set(&self) -> BrokerResult<()> {
        if self.contract != Self::CONTRACT
            || self.version != "1"
            || self.targets.is_empty()
            || self.targets.len() > 8
            || self
                .targets
                .windows(2)
                .any(|targets| targets[0].target_key.as_bytes() >= targets[1].target_key.as_bytes())
        {
            return Err(BrokerError::denied(
                "native broker credential-set contract is invalid",
            ));
        }

        Ok(())
    }
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct RunAuthorizationContext {
    pub run_id: String,
    pub run_started_at: String,
    pub evidence_contract: String,
    pub profiles: Vec<String>,
    pub claim_nonce: String,
    pub authorization_set_sha256: String,
    pub claim_request_sha256: String,
    pub consumption_receipt_sha256: String,
    pub authorization_bundle_sha256: String,
    pub probe_plan_sha256: String,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct EffectExecutionResponse {
    pub contract: String,
    pub version: String,
    pub descriptor: LiveEffectDescriptor,
    pub result: SignedDocument<BrokeredEffectExecutionResultEnvelope>,
    pub receipt: SignedDocument<BrokeredEffectExecutionReceiptEnvelope>,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct ConcurrentEffectExecutionResponse {
    pub contract: String,
    pub version: String,
    pub responses: Vec<EffectExecutionResponse>,
}

impl ConcurrentEffectExecutionResponse {
    pub const CONTRACT: &'static str =
        "cieplik206.fakturownia.concurrent-effect-execution-response";
}

impl EffectExecutionResponse {
    pub const CONTRACT: &'static str = "cieplik206.fakturownia.brokered-effect-execution-response";
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct CasAllocationRecord {
    contract: String,
    version: String,
    effect_id: String,
    effect_descriptor_sha256: String,
    launch_manifest_sha256: String,
    run_nonce: String,
    authorization_set_sha256: String,
    authorization_bundle_sha256: String,
    probe_plan_sha256: String,
    broker_policy_sha256: String,
    allocated_at: String,
}

pub trait ProviderTransport: Sync {
    fn execute(
        &self,
        credential: &ProviderCredential,
        request: &EffectExecutionRequest,
        body: &[u8],
    ) -> ExecutionObservation;

    fn observe(
        &self,
        _credential: &ProviderCredential,
        _proposal: &ReadObservationProposal,
    ) -> ReadExecutionObservation {
        ReadExecutionObservation::TransportFailure {
            request_started_at: OffsetDateTime::now_utc(),
        }
    }
}

pub struct Broker<'a, T: ProviderTransport> {
    pub trust_policy: &'a NativeBrokerTrustPolicyEnvelope,
    pub attestation: &'a SignedDocument<NativeSupervisorAttestationEnvelope>,
    pub authorization: &'a RunAuthorizationContext,
    pub credentials: &'a ProviderCredentialSet,
    pub cas_root: &'a Path,
    pub result_signing_key: &'a SigningKey,
    pub transport: &'a T,
}

impl<T: ProviderTransport> Broker<'_, T> {
    pub fn execute_concurrent_proposals(
        &self,
        batch: &ConcurrentEffectExecutionProposal,
        observed_at: OffsetDateTime,
    ) -> BrokerResult<ConcurrentEffectExecutionResponse> {
        batch.validate(&self.authorization.evidence_contract)?;
        let first = &batch.proposals[0];
        let second = &batch.proposals[1];
        let (first, second) = std::thread::scope(|scope| {
            let first = scope.spawn(|| self.execute_proposal(first, observed_at));
            let second = scope.spawn(|| self.execute_proposal(second, observed_at));

            (first.join(), second.join())
        });
        let first =
            first.map_err(|_| BrokerError::denied("first concurrent broker effect panicked"))??;
        let second = second
            .map_err(|_| BrokerError::denied("second concurrent broker effect panicked"))??;

        Ok(ConcurrentEffectExecutionResponse {
            contract: ConcurrentEffectExecutionResponse::CONTRACT.to_owned(),
            version: "1".to_owned(),
            responses: vec![first, second],
        })
    }

    pub fn observe_proposal(
        &self,
        proposal: &ReadObservationProposal,
        observed_at: OffsetDateTime,
    ) -> BrokerResult<BrokeredReadObservationResponse> {
        proposal.validate(&self.authorization.evidence_contract)?;
        let credential = self
            .credentials
            .select(&proposal.profile, &proposal.target_key)?;
        let (_, request_id_key) = credential.validate(&proposal.profile, &proposal.target_key)?;
        self.attestation
            .envelope
            .validate(self.trust_policy, observed_at)?;
        let observation = self.transport.observe(credential, proposal);

        if proposal.capability == "account.read" {
            verify_account_observation(credential, &observation)?;
        }

        let result = BrokeredReadObservationResultEnvelope::issue(
            self.trust_policy,
            self.attestation,
            self.result_signing_key,
            proposal,
            observation,
            &request_id_key,
            OffsetDateTime::now_utc(),
        )?;

        Ok(BrokeredReadObservationResponse {
            contract: BrokeredReadObservationResponse::CONTRACT.to_owned(),
            version: "1".to_owned(),
            result,
        })
    }

    pub fn execute_proposal(
        &self,
        proposal: &EffectExecutionProposal,
        observed_at: OffsetDateTime,
    ) -> BrokerResult<EffectExecutionResponse> {
        let (body, request_body_policy) =
            proposal.validate(&self.authorization.evidence_contract)?;
        let credential = self
            .credentials
            .select(&proposal.profile, &proposal.target_key)?;
        let (commitment_key, _) = credential.validate(&proposal.profile, &proposal.target_key)?;
        let descriptor = LiveEffectDescriptor {
            contract: LiveEffectDescriptor::CONTRACT.to_owned(),
            version: "1".to_owned(),
            evidence_contract: self.authorization.evidence_contract.clone(),
            run_id: self.authorization.run_id.clone(),
            effect_id: proposal.effect_id.clone(),
            effect_sequence: proposal.effect_sequence,
            profile: proposal.profile.clone(),
            target_key: proposal.target_key.clone(),
            capability: proposal.capability.clone(),
            semantic_effect: proposal.semantic_effect.clone(),
            http_method: proposal.http_method.clone(),
            endpoint_template: proposal.endpoint_template.clone(),
            commitment_scheme: COMMITMENT_SCHEME.to_owned(),
            target_origin_hmac_sha256: hmac_sha256_hex(
                &commitment_key,
                &commitment_material("target", credential.target_origin.as_bytes()),
            )?,
            operation_identity_hmac_sha256: hmac_sha256_hex(
                &commitment_key,
                &commitment_material(
                    "operation",
                    format!("{}\n{}", proposal.http_method, proposal.provider_path).as_bytes(),
                ),
            )?,
            request_body_hmac_sha256: hmac_sha256_hex(
                &commitment_key,
                &commitment_material("request-body", &body),
            )?,
            request_body_size_bytes: body.len(),
            request_body_policy: request_body_policy.to_owned(),
            launch_manifest_sha256: self.attestation.envelope.launch_manifest_sha256.clone(),
            supervisor_attestation_sha256: self.attestation.sha256()?,
            broker_policy_sha256: self.attestation.envelope.broker_policy_sha256.clone(),
            authorization_set_sha256: self.authorization.authorization_set_sha256.clone(),
            authorization_bundle_sha256: self.authorization.authorization_bundle_sha256.clone(),
            probe_plan_sha256: self.authorization.probe_plan_sha256.clone(),
            claim_request_sha256: self.authorization.claim_request_sha256.clone(),
            consumption_receipt_sha256: self.authorization.consumption_receipt_sha256.clone(),
            claim_nonce: self.authorization.claim_nonce.clone(),
            run_started_at: self.authorization.run_started_at.clone(),
            connect_timeout_ms: proposal.connect_timeout_ms,
            request_timeout_ms: proposal.request_timeout_ms,
            maximum_response_bytes: proposal.maximum_response_bytes,
        };
        let request = EffectExecutionRequest {
            contract: EffectExecutionRequest::CONTRACT.to_owned(),
            version: "1".to_owned(),
            descriptor,
            provider_path: proposal.provider_path.clone(),
            request_body_base64: proposal.request_body_base64.clone(),
        };
        let result = self.execute(&request, observed_at)?;
        let receipt = BrokeredEffectExecutionReceiptEnvelope::issue(
            self.trust_policy,
            self.result_signing_key,
            request.descriptor.clone(),
            &result.envelope,
            OffsetDateTime::now_utc(),
        )?;

        Ok(EffectExecutionResponse {
            contract: EffectExecutionResponse::CONTRACT.to_owned(),
            version: "1".to_owned(),
            descriptor: request.descriptor,
            result,
            receipt,
        })
    }

    pub fn execute(
        &self,
        request: &EffectExecutionRequest,
        observed_at: OffsetDateTime,
    ) -> BrokerResult<SignedDocument<BrokeredEffectExecutionResultEnvelope>> {
        let credential = self
            .credentials
            .select(&request.descriptor.profile, &request.descriptor.target_key)?;
        let (commitment_key, request_id_key) =
            credential.validate(&request.descriptor.profile, &request.descriptor.target_key)?;
        self.attestation
            .envelope
            .validate(self.trust_policy, observed_at)?;
        let body = request.validate(&credential.target_origin, &commitment_key)?;
        self.validate_bindings(request)?;
        let descriptor_sha256 = sha256_hex(&canonical::encode(&request.descriptor)?);
        let effect_id = request.descriptor.effect_id.clone();

        let allocation = CasAllocationRecord {
            contract: "cieplik206.fakturownia.native-broker-cas-allocation".to_owned(),
            version: "1".to_owned(),
            effect_id: effect_id.clone(),
            effect_descriptor_sha256: descriptor_sha256.clone(),
            launch_manifest_sha256: request.descriptor.launch_manifest_sha256.clone(),
            run_nonce: self.attestation.envelope.run_nonce.clone(),
            authorization_set_sha256: request.descriptor.authorization_set_sha256.clone(),
            authorization_bundle_sha256: request.descriptor.authorization_bundle_sha256.clone(),
            probe_plan_sha256: request.descriptor.probe_plan_sha256.clone(),
            broker_policy_sha256: request.descriptor.broker_policy_sha256.clone(),
            allocated_at: format_utc_microsecond(observed_at)?,
        };
        let cas_record_sha256 = sha256_hex(&canonical::encode(&allocation)?);
        let Some(claim) = cas::claim(self.cas_root, &effect_id)? else {
            return self.recover_existing(request, &request_id_key, observed_at);
        };
        claim.persist(&allocation)?;

        let observation = self.transport.execute(credential, request, &body);
        let result = BrokeredEffectExecutionResultEnvelope::issue(
            self.trust_policy,
            self.attestation,
            self.result_signing_key,
            descriptor_sha256,
            effect_id.clone(),
            cas_record_sha256,
            observation,
            &request_id_key,
            OffsetDateTime::now_utc(),
        )?;
        cas::store_record(self.cas_root, &effect_id, "result", &result)?;

        Ok(result)
    }

    fn validate_bindings(&self, request: &EffectExecutionRequest) -> BrokerResult<()> {
        let descriptor = &request.descriptor;
        let attestation_sha256 = self.attestation.sha256()?;

        if descriptor.run_id != self.authorization.run_id
            || descriptor.run_started_at != self.authorization.run_started_at
            || descriptor.evidence_contract != self.authorization.evidence_contract
            || !self.authorization.profiles.contains(&descriptor.profile)
            || !constant_time_hex_equal(
                &descriptor.claim_request_sha256,
                &self.authorization.claim_request_sha256,
            )
            || !constant_time_hex_equal(
                &descriptor.consumption_receipt_sha256,
                &self.authorization.consumption_receipt_sha256,
            )
            || !constant_time_hex_equal(
                &descriptor.launch_manifest_sha256,
                &self.attestation.envelope.launch_manifest_sha256,
            )
            || !constant_time_hex_equal(
                &descriptor.authorization_set_sha256,
                &self.attestation.envelope.authorization_set_sha256,
            )
            || !constant_time_hex_equal(
                &descriptor.authorization_bundle_sha256,
                &self.attestation.envelope.authorization_bundle_sha256,
            )
            || !constant_time_hex_equal(
                &descriptor.probe_plan_sha256,
                &self.attestation.envelope.probe_plan_sha256,
            )
            || !constant_time_hex_equal(
                &descriptor.broker_policy_sha256,
                &self.attestation.envelope.broker_policy_sha256,
            )
            || !constant_time_hex_equal(
                &descriptor.supervisor_attestation_sha256,
                &attestation_sha256,
            )
            || descriptor.claim_nonce != self.authorization.claim_nonce
        {
            return Err(BrokerError::denied(
                "native broker effect does not bind the authorized run",
            ));
        }

        Ok(())
    }

    fn recover_existing(
        &self,
        request: &EffectExecutionRequest,
        request_id_key: &[u8],
        observed_at: OffsetDateTime,
    ) -> BrokerResult<SignedDocument<BrokeredEffectExecutionResultEnvelope>> {
        let effect_id = &request.descriptor.effect_id;

        if let Some(bytes) = cas::read_record(self.cas_root, effect_id, "result", 2_097_152)? {
            let stored: SignedDocument<BrokeredEffectExecutionResultEnvelope> =
                serde_json::from_slice(&bytes)
                    .map_err(|_| BrokerError::denied("stored broker result is invalid"))?;
            stored.envelope.validate()?;
            verify_base64(
                &self.trust_policy.result_verifying_key()?,
                &stored.canonical_envelope()?,
                &stored.signature,
            )?;

            if stored.envelope.effect_id != *effect_id
                || stored.envelope.effect_descriptor_sha256
                    != sha256_hex(&canonical::encode(&request.descriptor)?)
                || stored.envelope.supervisor_attestation_sha256 != self.attestation.sha256()?
            {
                return Err(BrokerError::denied(
                    "stored broker result belongs to another effect",
                ));
            }

            return Ok(stored);
        }

        let allocation = cas::read_record(self.cas_root, effect_id, "allocation", 65_536)?
            .ok_or_else(|| BrokerError::denied("broker CAS allocation disappeared"))?;
        let allocation_record: CasAllocationRecord = serde_json::from_slice(&allocation)
            .map_err(|_| BrokerError::denied("broker CAS allocation is invalid"))?;
        let descriptor_sha256 = sha256_hex(&canonical::encode(&request.descriptor)?);

        if allocation_record.effect_id != *effect_id
            || allocation_record.effect_descriptor_sha256 != descriptor_sha256
            || allocation_record.launch_manifest_sha256 != request.descriptor.launch_manifest_sha256
            || allocation_record.authorization_set_sha256
                != request.descriptor.authorization_set_sha256
            || allocation_record.authorization_bundle_sha256
                != request.descriptor.authorization_bundle_sha256
            || allocation_record.probe_plan_sha256 != request.descriptor.probe_plan_sha256
            || allocation_record.broker_policy_sha256 != request.descriptor.broker_policy_sha256
        {
            return Err(BrokerError::denied(
                "broker CAS allocation belongs to another effect",
            ));
        }

        let result = BrokeredEffectExecutionResultEnvelope::issue(
            self.trust_policy,
            self.attestation,
            self.result_signing_key,
            descriptor_sha256,
            effect_id.clone(),
            sha256_hex(&allocation),
            ExecutionObservation::PossiblyApplied {
                request_started_at: observed_at,
            },
            request_id_key,
            observed_at,
        )?;
        cas::store_record(self.cas_root, effect_id, "result", &result)?;

        Ok(result)
    }
}

#[cfg(feature = "network")]
#[derive(Clone, Copy, Debug, Default)]
pub struct UreqProviderTransport;

#[cfg(feature = "network")]
impl ProviderTransport for UreqProviderTransport {
    fn execute(
        &self,
        credential: &ProviderCredential,
        request: &EffectExecutionRequest,
        body: &[u8],
    ) -> ExecutionObservation {
        let request_started_at = OffsetDateTime::now_utc();

        match execute_http(credential, request, body) {
            Ok(response) => ExecutionObservation::Applied {
                request_started_at,
                response_received_at: OffsetDateTime::now_utc(),
                http_status: response.status,
                content_type: response.content_type,
                provider_request_id: response.provider_request_id,
                response_body: response.body,
            },
            Err(()) => ExecutionObservation::PossiblyApplied { request_started_at },
        }
    }

    fn observe(
        &self,
        credential: &ProviderCredential,
        proposal: &ReadObservationProposal,
    ) -> ReadExecutionObservation {
        let request_started_at = OffsetDateTime::now_utc();

        match execute_read_http(credential, proposal) {
            Ok(response) => ReadExecutionObservation::Observed {
                request_started_at,
                response_received_at: OffsetDateTime::now_utc(),
                http_status: response.status,
                content_type: response.content_type,
                provider_request_id: response.provider_request_id,
                response_body: response.body,
            },
            Err(()) => ReadExecutionObservation::TransportFailure { request_started_at },
        }
    }
}

#[cfg(feature = "network")]
struct HttpResponse {
    status: u16,
    content_type: Option<String>,
    provider_request_id: Option<String>,
    body: Vec<u8>,
}

#[cfg(feature = "network")]
fn execute_http(
    credential: &ProviderCredential,
    request: &EffectExecutionRequest,
    body: &[u8],
) -> Result<HttpResponse, ()> {
    execute_http_request(
        credential,
        &request.descriptor.http_method,
        &request.provider_path,
        request.descriptor.connect_timeout_ms,
        request.descriptor.request_timeout_ms,
        request.descriptor.maximum_response_bytes,
        body,
        "application/json",
    )
}

#[cfg(feature = "network")]
fn execute_read_http(
    credential: &ProviderCredential,
    proposal: &ReadObservationProposal,
) -> Result<HttpResponse, ()> {
    execute_http_request(
        credential,
        &proposal.http_method,
        &proposal.provider_path,
        proposal.connect_timeout_ms,
        proposal.request_timeout_ms,
        proposal.maximum_response_bytes,
        &[],
        if proposal.capability == "invoice.pdf.download" {
            "application/pdf"
        } else {
            "application/json"
        },
    )
}

#[cfg(feature = "network")]
#[allow(clippy::too_many_arguments)]
fn execute_http_request(
    credential: &ProviderCredential,
    method: &str,
    provider_path: &str,
    connect_timeout_ms: u32,
    request_timeout_ms: u32,
    maximum_response_bytes: usize,
    body: &[u8],
    accept: &str,
) -> Result<HttpResponse, ()> {
    let mut url =
        Url::parse(&format!("{}{}", credential.target_origin, provider_path)).map_err(|_| ())?;
    url.query_pairs_mut()
        .append_pair("api_token", &credential.api_token);
    let config = ureq::Agent::config_builder()
        .max_redirects(0)
        .timeout_connect(Some(StdDuration::from_millis(u64::from(
            connect_timeout_ms,
        ))))
        .timeout_global(Some(StdDuration::from_millis(u64::from(
            request_timeout_ms,
        ))))
        .build();
    let agent: ureq::Agent = config.into();
    let response = if method == "POST" {
        agent
            .post(url.as_str())
            .header("content-type", "application/json")
            .header("accept", accept)
            .send(body)
    } else {
        agent.get(url.as_str()).header("accept", accept).call()
    };
    let mut response = response.map_err(|_| ())?;
    let status = response.status().as_u16();
    let content_type = response
        .headers()
        .get("content-type")
        .and_then(|value| value.to_str().ok())
        .map(str::to_owned);
    let provider_request_id = response
        .headers()
        .get("x-request-id")
        .and_then(|value| value.to_str().ok())
        .map(str::to_owned);
    let body = response
        .body_mut()
        .with_config()
        .limit(maximum_response_bytes as u64 + 1)
        .read_to_vec()
        .map_err(|_| ())?;

    if body.len() > maximum_response_bytes {
        return Err(());
    }

    Ok(HttpResponse {
        status,
        content_type,
        provider_request_id,
        body,
    })
}

pub(crate) fn verify_account_observation(
    credential: &ProviderCredential,
    observation: &ReadExecutionObservation,
) -> BrokerResult<()> {
    let ReadExecutionObservation::Observed {
        http_status,
        response_body,
        ..
    } = observation
    else {
        return Err(BrokerError::denied(
            "native broker account preflight did not receive a response",
        ));
    };

    if *http_status != 200 {
        return Err(BrokerError::denied(
            "native broker account preflight did not return HTTP 200",
        ));
    }

    let document: serde_json::Value = serde_json::from_slice(response_body)
        .map_err(|_| BrokerError::denied("native broker account response is invalid"))?;
    let account_id = document
        .as_object()
        .and_then(|object| object.get("id"))
        .and_then(|value| match value {
            serde_json::Value::Number(value) => Some(value.to_string()),
            serde_json::Value::String(value) => Some(value.clone()),
            _ => None,
        })
        .filter(|value| {
            !value.is_empty()
                && value.len() <= 19
                && !value.starts_with('0')
                && value.bytes().all(|byte| byte.is_ascii_digit())
        })
        .ok_or_else(|| BrokerError::denied("native broker account ID is invalid"))?;
    let host = Url::parse(&credential.target_origin)
        .ok()
        .and_then(|url| url.host_str().map(str::to_lowercase))
        .ok_or_else(|| BrokerError::denied("native broker account host is invalid"))?;
    let material = if credential.environment == "ksef_demo" {
        format!(
            "fakturownia-s0.4|{}|{host}|{account_id}",
            credential.profile
        )
    } else {
        format!(
            "fakturownia-s0.3|{}|{host}|{account_id}",
            credential.environment
        )
    };
    let fingerprint = sha256_hex(material.as_bytes());

    if !constant_time_hex_equal(&fingerprint, &credential.expected_account_fingerprint) {
        return Err(BrokerError::denied(
            "native broker account fingerprint does not match the signed plan",
        ));
    }

    Ok(())
}

#[must_use]
pub fn default_policy_path() -> PathBuf {
    PathBuf::from("/etc/cieplik206/fakturownia-live-evidence/native-supervisor-policy.json")
}

#[cfg(test)]
mod tests {
    use std::sync::Barrier;
    use std::sync::atomic::{AtomicUsize, Ordering};

    use base64::Engine;
    use base64::engine::general_purpose::STANDARD;
    use ed25519_dalek::SigningKey;
    use rand::rngs::OsRng;
    use tempfile::tempdir;
    use time::{Duration, OffsetDateTime};

    use crate::canonical;
    use crate::crypto::{hmac_sha256_hex, verify_base64};
    use crate::observation::ReadExecutionObservation;
    use crate::protocol::{
        ConcurrentEffectExecutionProposal, EffectExecutionProposal, EffectExecutionRequest,
        LiveEffectDescriptor, ReadObservationProposal, commitment_material,
    };
    use crate::result::{EffectDisposition, ExecutionObservation};
    use crate::trust::{
        NativeBrokerTrustPolicyEnvelope, NativeSupervisorAttestationEnvelope, RoleSigner,
        SignedDocument, format_utc_microsecond,
    };

    use super::{
        Broker, ProviderCredential, ProviderCredentialSet, ProviderTransport,
        RunAuthorizationContext,
    };

    struct CountingTransport {
        calls: AtomicUsize,
    }

    struct ConcurrentTransport {
        barrier: Barrier,
        active: AtomicUsize,
        maximum_active: AtomicUsize,
    }

    impl ProviderTransport for ConcurrentTransport {
        fn execute(
            &self,
            _credential: &ProviderCredential,
            _request: &EffectExecutionRequest,
            _body: &[u8],
        ) -> ExecutionObservation {
            let active = self.active.fetch_add(1, Ordering::SeqCst) + 1;
            self.maximum_active.fetch_max(active, Ordering::SeqCst);
            self.barrier.wait();
            self.active.fetch_sub(1, Ordering::SeqCst);
            let now = OffsetDateTime::now_utc();

            ExecutionObservation::Applied {
                request_started_at: now,
                response_received_at: now + Duration::milliseconds(10),
                http_status: 201,
                content_type: Some("application/json".to_owned()),
                provider_request_id: None,
                response_body: br#"{"id":123}"#.to_vec(),
            }
        }
    }

    struct AccountTransport;

    impl ProviderTransport for AccountTransport {
        fn execute(
            &self,
            _credential: &ProviderCredential,
            _request: &EffectExecutionRequest,
            _body: &[u8],
        ) -> ExecutionObservation {
            ExecutionObservation::Denied
        }

        fn observe(
            &self,
            _credential: &ProviderCredential,
            _proposal: &ReadObservationProposal,
        ) -> ReadExecutionObservation {
            let now = OffsetDateTime::now_utc();

            ReadExecutionObservation::Observed {
                request_started_at: now,
                response_received_at: now + Duration::milliseconds(10),
                http_status: 200,
                content_type: Some("application/json".to_owned()),
                provider_request_id: Some("read-request-1".to_owned()),
                response_body: br#"{"id":123}"#.to_vec(),
            }
        }
    }

    impl ProviderTransport for CountingTransport {
        fn execute(
            &self,
            _credential: &ProviderCredential,
            _request: &EffectExecutionRequest,
            _body: &[u8],
        ) -> ExecutionObservation {
            self.calls.fetch_add(1, Ordering::SeqCst);
            let now = OffsetDateTime::now_utc();

            ExecutionObservation::Applied {
                request_started_at: now,
                response_received_at: now + Duration::milliseconds(10),
                http_status: 201,
                content_type: Some("application/json".to_owned()),
                provider_request_id: Some("provider-request-1".to_owned()),
                response_body: br#"{"id":123}"#.to_vec(),
            }
        }
    }

    struct Fixture {
        trust_policy: NativeBrokerTrustPolicyEnvelope,
        attestation: SignedDocument<NativeSupervisorAttestationEnvelope>,
        result_key: SigningKey,
        credentials: ProviderCredentialSet,
        authorization: RunAuthorizationContext,
        request: EffectExecutionRequest,
        now: OffsetDateTime,
    }

    fn fixture() -> Result<Fixture, Box<dyn std::error::Error>> {
        let supervisor_key = SigningKey::generate(&mut OsRng);
        let result_key = SigningKey::generate(&mut OsRng);
        let now = OffsetDateTime::now_utc();
        let trust_policy = NativeBrokerTrustPolicyEnvelope {
            contract: NativeBrokerTrustPolicyEnvelope::CONTRACT.to_owned(),
            version: "1".to_owned(),
            algorithm: "Ed25519".to_owned(),
            signer_id: "deployment-policy-1".to_owned(),
            issued_at: format_utc_microsecond(now - Duration::minutes(1))?,
            expires_at: format_utc_microsecond(now + Duration::hours(1))?,
            broker_policy_sha256: "1".repeat(64),
            supervisor_semantics_sha256: "2".repeat(64),
            argv_sha256: "3".repeat(64),
            environment_sha256: "4".repeat(64),
            probe_uid: 991,
            probe_gid: 991,
            supervisor_signer: RoleSigner {
                id: "native-supervisor-1".to_owned(),
                algorithm: "Ed25519".to_owned(),
                public_key: STANDARD.encode(supervisor_key.verifying_key().as_bytes()),
            },
            effect_result_signer: RoleSigner {
                id: "native-effect-result-1".to_owned(),
                algorithm: "Ed25519".to_owned(),
                public_key: STANDARD.encode(result_key.verifying_key().as_bytes()),
            },
        };
        let attestation = NativeSupervisorAttestationEnvelope::issue(
            &trust_policy,
            &supervisor_key,
            "5".repeat(64),
            STANDARD.encode([6_u8; 32]),
            "7".repeat(64),
            "8".repeat(64),
            "9".repeat(64),
            now,
        )?;
        let commitment_key = [8_u8; 32];
        let request_id_key = [9_u8; 32];
        let target = "https://demo.fakturownia.pl";
        let path = "/invoices.json";
        let body = br#"{"invoice":{"kind":"vat"}}"#;
        let descriptor = LiveEffectDescriptor {
            contract: LiveEffectDescriptor::CONTRACT.to_owned(),
            version: "1".to_owned(),
            evidence_contract: "fakturownia-invoice-identity-s0.3-v1".to_owned(),
            run_id: "a".repeat(32),
            effect_id: "b".repeat(32),
            effect_sequence: 1,
            profile: "invoice_identity".to_owned(),
            target_key: "primary".to_owned(),
            capability: "invoice.vat.issue".to_owned(),
            semantic_effect: "invoice_create".to_owned(),
            http_method: "POST".to_owned(),
            endpoint_template: "/invoices.json".to_owned(),
            commitment_scheme: "hmac-sha256-ephemeral-run-key-v1".to_owned(),
            target_origin_hmac_sha256: hmac_sha256_hex(
                &commitment_key,
                &commitment_material("target", target.as_bytes()),
            )?,
            operation_identity_hmac_sha256: hmac_sha256_hex(
                &commitment_key,
                &commitment_material("operation", format!("POST\n{path}").as_bytes()),
            )?,
            request_body_hmac_sha256: hmac_sha256_hex(
                &commitment_key,
                &commitment_material("request-body", body),
            )?,
            request_body_size_bytes: body.len(),
            request_body_policy: "required_non_empty".to_owned(),
            launch_manifest_sha256: attestation.envelope.launch_manifest_sha256.clone(),
            supervisor_attestation_sha256: attestation.sha256()?,
            broker_policy_sha256: attestation.envelope.broker_policy_sha256.clone(),
            authorization_set_sha256: attestation.envelope.authorization_set_sha256.clone(),
            authorization_bundle_sha256: attestation.envelope.authorization_bundle_sha256.clone(),
            probe_plan_sha256: attestation.envelope.probe_plan_sha256.clone(),
            claim_request_sha256: "c".repeat(64),
            consumption_receipt_sha256: "d".repeat(64),
            claim_nonce: attestation.envelope.run_nonce.clone(),
            run_started_at: format_utc_microsecond(now)?,
            connect_timeout_ms: 1_000,
            request_timeout_ms: 5_000,
            maximum_response_bytes: 1_048_576,
        };
        let request = EffectExecutionRequest {
            contract: EffectExecutionRequest::CONTRACT.to_owned(),
            version: "1".to_owned(),
            descriptor,
            provider_path: path.to_owned(),
            request_body_base64: STANDARD.encode(body),
        };
        let claim_nonce = attestation.envelope.run_nonce.clone();

        Ok(Fixture {
            trust_policy,
            attestation,
            result_key,
            credentials: ProviderCredentialSet {
                contract: ProviderCredentialSet::CONTRACT.to_owned(),
                version: "1".to_owned(),
                targets: vec![
                    ProviderCredential {
                        contract: ProviderCredential::CONTRACT.to_owned(),
                        version: "1".to_owned(),
                        environment: "demo_pl".to_owned(),
                        profile: "invoice_identity".to_owned(),
                        target_key: "primary".to_owned(),
                        target_origin: target.to_owned(),
                        api_token: "never-log-this-token".to_owned(),
                        expected_account_fingerprint: "1".repeat(64),
                        authorization_binding_key_base64: STANDARD.encode([12_u8; 32]),
                        commitment_key_base64: STANDARD.encode(commitment_key),
                        provider_request_id_hmac_key_base64: STANDARD.encode(request_id_key),
                    },
                    ProviderCredential {
                        contract: ProviderCredential::CONTRACT.to_owned(),
                        version: "1".to_owned(),
                        environment: "demo_pl".to_owned(),
                        profile: "invoice_identity".to_owned(),
                        target_key: "secondary".to_owned(),
                        target_origin: "https://secondary-demo.fakturownia.pl".to_owned(),
                        api_token: "another-never-log-token".to_owned(),
                        expected_account_fingerprint: "2".repeat(64),
                        authorization_binding_key_base64: STANDARD.encode([12_u8; 32]),
                        commitment_key_base64: STANDARD.encode([10_u8; 32]),
                        provider_request_id_hmac_key_base64: STANDARD.encode([11_u8; 32]),
                    },
                ],
            },
            authorization: RunAuthorizationContext {
                run_id: "a".repeat(32),
                run_started_at: format_utc_microsecond(now)?,
                evidence_contract: "fakturownia-invoice-identity-s0.3-v1".to_owned(),
                profiles: vec!["invoice_identity".to_owned()],
                claim_nonce,
                authorization_set_sha256: "7".repeat(64),
                claim_request_sha256: "c".repeat(64),
                consumption_receipt_sha256: "d".repeat(64),
                authorization_bundle_sha256: "8".repeat(64),
                probe_plan_sha256: "9".repeat(64),
            },
            request,
            now,
        })
    }

    fn proposal(fixture: &Fixture) -> EffectExecutionProposal {
        EffectExecutionProposal {
            contract: EffectExecutionProposal::CONTRACT.to_owned(),
            version: "1".to_owned(),
            evidence_contract: fixture.request.descriptor.evidence_contract.clone(),
            effect_id: fixture.request.descriptor.effect_id.clone(),
            effect_sequence: fixture.request.descriptor.effect_sequence,
            profile: fixture.request.descriptor.profile.clone(),
            target_key: fixture.request.descriptor.target_key.clone(),
            capability: fixture.request.descriptor.capability.clone(),
            semantic_effect: fixture.request.descriptor.semantic_effect.clone(),
            http_method: fixture.request.descriptor.http_method.clone(),
            endpoint_template: fixture.request.descriptor.endpoint_template.clone(),
            provider_path: fixture.request.provider_path.clone(),
            request_body_base64: fixture.request.request_body_base64.clone(),
            connect_timeout_ms: fixture.request.descriptor.connect_timeout_ms,
            request_timeout_ms: fixture.request.descriptor.request_timeout_ms,
            maximum_response_bytes: fixture.request.descriptor.maximum_response_bytes,
        }
    }

    #[test]
    fn root_broker_builds_secret_commitments_from_a_bounded_proposal()
    -> Result<(), Box<dyn std::error::Error>> {
        let fixture = fixture()?;
        let cas = tempdir()?;
        let transport = CountingTransport {
            calls: AtomicUsize::new(0),
        };
        let broker = Broker {
            trust_policy: &fixture.trust_policy,
            attestation: &fixture.attestation,
            authorization: &fixture.authorization,
            credentials: &fixture.credentials,
            cas_root: cas.path(),
            result_signing_key: &fixture.result_key,
            transport: &transport,
        };
        let proposal = proposal(&fixture);
        let response = broker.execute_proposal(&proposal, fixture.now)?;

        assert_eq!(transport.calls.load(Ordering::SeqCst), 1);
        assert_eq!(response.contract, super::EffectExecutionResponse::CONTRACT);
        assert_eq!(response.descriptor.effect_id, proposal.effect_id);
        assert_eq!(
            response.result.envelope.effect_descriptor_sha256,
            crate::crypto::sha256_hex(&canonical::encode(&response.descriptor)?),
        );
        assert_eq!(
            response.descriptor.target_origin_hmac_sha256,
            fixture.request.descriptor.target_origin_hmac_sha256,
        );

        Ok(())
    }

    #[test]
    fn selects_the_exact_authorized_target_without_exposing_its_credential()
    -> Result<(), Box<dyn std::error::Error>> {
        let fixture = fixture()?;
        let cas = tempdir()?;
        let transport = CountingTransport {
            calls: AtomicUsize::new(0),
        };
        let broker = Broker {
            trust_policy: &fixture.trust_policy,
            attestation: &fixture.attestation,
            authorization: &fixture.authorization,
            credentials: &fixture.credentials,
            cas_root: cas.path(),
            result_signing_key: &fixture.result_key,
            transport: &transport,
        };
        let mut proposal = proposal(&fixture);
        proposal.target_key = "secondary".to_owned();
        proposal.effect_id = "e".repeat(32);
        let response = broker.execute_proposal(&proposal, fixture.now)?;
        let expected_target_hmac = hmac_sha256_hex(
            &[10_u8; 32],
            &commitment_material("target", b"https://secondary-demo.fakturownia.pl"),
        )?;

        assert_eq!(transport.calls.load(Ordering::SeqCst), 1);
        assert_eq!(response.descriptor.target_key, "secondary");
        assert_eq!(
            response.descriptor.target_origin_hmac_sha256,
            expected_target_hmac,
        );

        Ok(())
    }

    #[test]
    fn rejects_an_unsorted_or_profile_mismatched_credential_set_before_cas_or_transport()
    -> Result<(), Box<dyn std::error::Error>> {
        let mut fixture = fixture()?;
        let cas = tempdir()?;
        let transport = CountingTransport {
            calls: AtomicUsize::new(0),
        };
        fixture.credentials.targets.swap(0, 1);
        let proposal = proposal(&fixture);
        let broker = Broker {
            trust_policy: &fixture.trust_policy,
            attestation: &fixture.attestation,
            authorization: &fixture.authorization,
            credentials: &fixture.credentials,
            cas_root: cas.path(),
            result_signing_key: &fixture.result_key,
            transport: &transport,
        };

        assert!(broker.execute_proposal(&proposal, fixture.now).is_err());
        assert_eq!(transport.calls.load(Ordering::SeqCst), 0);
        assert_eq!(std::fs::read_dir(cas.path())?.count(), 0);

        fixture
            .credentials
            .targets
            .sort_by(|left, right| left.target_key.as_bytes().cmp(right.target_key.as_bytes()));
        fixture.credentials.targets[0].profile = "auto_block".to_owned();
        let broker = Broker {
            trust_policy: &fixture.trust_policy,
            attestation: &fixture.attestation,
            authorization: &fixture.authorization,
            credentials: &fixture.credentials,
            cas_root: cas.path(),
            result_signing_key: &fixture.result_key,
            transport: &transport,
        };

        assert!(broker.execute_proposal(&proposal, fixture.now).is_err());
        assert_eq!(transport.calls.load(Ordering::SeqCst), 0);
        assert_eq!(std::fs::read_dir(cas.path())?.count(), 0);

        Ok(())
    }

    #[test]
    fn rejects_a_non_allowlisted_proposal_before_cas_or_transport()
    -> Result<(), Box<dyn std::error::Error>> {
        let fixture = fixture()?;
        let cas = tempdir()?;
        let transport = CountingTransport {
            calls: AtomicUsize::new(0),
        };
        let broker = Broker {
            trust_policy: &fixture.trust_policy,
            attestation: &fixture.attestation,
            authorization: &fixture.authorization,
            credentials: &fixture.credentials,
            cas_root: cas.path(),
            result_signing_key: &fixture.result_key,
            transport: &transport,
        };
        let mut proposal = proposal(&fixture);
        proposal.provider_path = "/clients.json".to_owned();

        assert!(broker.execute_proposal(&proposal, fixture.now).is_err());
        assert_eq!(transport.calls.load(Ordering::SeqCst), 0);
        assert_eq!(std::fs::read_dir(cas.path())?.count(), 0);

        Ok(())
    }

    #[test]
    fn executes_one_provider_effect_and_replays_only_the_signed_result()
    -> Result<(), Box<dyn std::error::Error>> {
        let fixture = fixture()?;
        let cas = tempdir()?;
        let transport = CountingTransport {
            calls: AtomicUsize::new(0),
        };
        let broker = Broker {
            trust_policy: &fixture.trust_policy,
            attestation: &fixture.attestation,
            authorization: &fixture.authorization,
            credentials: &fixture.credentials,
            cas_root: cas.path(),
            result_signing_key: &fixture.result_key,
            transport: &transport,
        };

        let first = broker.execute(&fixture.request, fixture.now)?;
        let second = broker.execute(&fixture.request, fixture.now + Duration::seconds(1))?;

        assert_eq!(transport.calls.load(Ordering::SeqCst), 1);
        assert_eq!(first.envelope.disposition, EffectDisposition::Applied);
        assert_eq!(canonical::encode(&first)?, canonical::encode(&second)?);
        assert_eq!(std::fs::read_dir(cas.path())?.count(), 2);

        Ok(())
    }

    #[test]
    fn signs_a_secret_free_effect_receipt_bound_to_the_exact_execution()
    -> Result<(), Box<dyn std::error::Error>> {
        let fixture = fixture()?;
        let cas = tempdir()?;
        let transport = CountingTransport {
            calls: AtomicUsize::new(0),
        };
        let broker = Broker {
            trust_policy: &fixture.trust_policy,
            attestation: &fixture.attestation,
            authorization: &fixture.authorization,
            credentials: &fixture.credentials,
            cas_root: cas.path(),
            result_signing_key: &fixture.result_key,
            transport: &transport,
        };

        let response = broker.execute_proposal(&proposal(&fixture), fixture.now)?;
        let receipt = canonical::encode(&response.receipt.envelope)?;
        let receipt_json = std::str::from_utf8(&receipt)?;

        verify_base64(
            &fixture.result_key.verifying_key(),
            &receipt,
            &response.receipt.signature,
        )?;
        assert_eq!(transport.calls.load(Ordering::SeqCst), 1);
        assert_eq!(
            response.receipt.envelope.descriptor.effect_id,
            "b".repeat(32)
        );
        assert_eq!(
            response.receipt.envelope.response_body_sha256,
            response.result.envelope.response_body_sha256,
        );
        assert_eq!(
            response.receipt.envelope.response_size_bytes,
            response.result.envelope.response_size_bytes,
        );
        assert!(!receipt_json.contains("response_body_base64"));
        assert!(!receipt_json.contains("never-log-this-token"));
        assert!(!receipt_json.contains("provider-request-1"));

        Ok(())
    }

    #[test]
    fn denies_cross_run_substitution_before_transport() -> Result<(), Box<dyn std::error::Error>> {
        let mut fixture = fixture()?;
        let cas = tempdir()?;
        let transport = CountingTransport {
            calls: AtomicUsize::new(0),
        };
        fixture.request.descriptor.claim_request_sha256 = "e".repeat(64);
        let broker = Broker {
            trust_policy: &fixture.trust_policy,
            attestation: &fixture.attestation,
            authorization: &fixture.authorization,
            credentials: &fixture.credentials,
            cas_root: cas.path(),
            result_signing_key: &fixture.result_key,
            transport: &transport,
        };

        assert!(broker.execute(&fixture.request, fixture.now).is_err());
        assert_eq!(transport.calls.load(Ordering::SeqCst), 0);
        assert_eq!(std::fs::read_dir(cas.path())?.count(), 0);

        Ok(())
    }

    #[test]
    fn never_retries_after_a_crash_window_without_a_result()
    -> Result<(), Box<dyn std::error::Error>> {
        let fixture = fixture()?;
        let cas = tempdir()?;
        let allocation = serde_json::json!({
            "allocated_at": format_utc_microsecond(fixture.now)?,
            "authorization_set_sha256": fixture.request.descriptor.authorization_set_sha256,
            "authorization_bundle_sha256": fixture.request.descriptor.authorization_bundle_sha256,
            "probe_plan_sha256": fixture.request.descriptor.probe_plan_sha256,
            "broker_policy_sha256": fixture.request.descriptor.broker_policy_sha256,
            "contract": "cieplik206.fakturownia.native-broker-cas-allocation",
            "effect_descriptor_sha256": crate::crypto::sha256_hex(&canonical::encode(&fixture.request.descriptor)?),
            "effect_id": fixture.request.descriptor.effect_id,
            "launch_manifest_sha256": fixture.request.descriptor.launch_manifest_sha256,
            "run_nonce": fixture.attestation.envelope.run_nonce,
            "version": "1"
        });
        crate::cas::store_record(
            cas.path(),
            &fixture.request.descriptor.effect_id,
            "allocation",
            &allocation,
        )?;
        let transport = CountingTransport {
            calls: AtomicUsize::new(0),
        };
        let broker = Broker {
            trust_policy: &fixture.trust_policy,
            attestation: &fixture.attestation,
            authorization: &fixture.authorization,
            credentials: &fixture.credentials,
            cas_root: cas.path(),
            result_signing_key: &fixture.result_key,
            transport: &transport,
        };

        let result = broker.execute(&fixture.request, fixture.now + Duration::seconds(1))?;

        assert_eq!(transport.calls.load(Ordering::SeqCst), 0);
        assert_eq!(
            result.envelope.disposition,
            EffectDisposition::PossiblyApplied
        );

        Ok(())
    }

    #[test]
    fn signs_a_read_only_account_observation_after_fingerprint_validation()
    -> Result<(), Box<dyn std::error::Error>> {
        let mut fixture = fixture()?;
        fixture.credentials.targets[0].expected_account_fingerprint =
            crate::crypto::sha256_hex(b"fakturownia-s0.3|demo_pl|demo.fakturownia.pl|123");
        let cas = tempdir()?;
        let transport = AccountTransport;
        let broker = Broker {
            trust_policy: &fixture.trust_policy,
            attestation: &fixture.attestation,
            authorization: &fixture.authorization,
            credentials: &fixture.credentials,
            cas_root: cas.path(),
            result_signing_key: &fixture.result_key,
            transport: &transport,
        };
        let proposal = ReadObservationProposal {
            contract: ReadObservationProposal::CONTRACT.to_owned(),
            version: "1".to_owned(),
            evidence_contract: fixture.authorization.evidence_contract.clone(),
            observation_id: "1".repeat(32),
            profile: "invoice_identity".to_owned(),
            target_key: "primary".to_owned(),
            capability: "account.read".to_owned(),
            http_method: "GET".to_owned(),
            endpoint_template: "/account.json".to_owned(),
            provider_path: "/account.json".to_owned(),
            connect_timeout_ms: 1_000,
            request_timeout_ms: 5_000,
            maximum_response_bytes: 65_536,
        };
        let response = broker.observe_proposal(&proposal, fixture.now)?;

        assert_eq!(response.result.envelope.http_status, 200);
        assert_eq!(
            response.result.envelope.observation_id,
            proposal.observation_id
        );
        assert_eq!(std::fs::read_dir(cas.path())?.count(), 0);

        Ok(())
    }

    #[test]
    fn dispatches_the_same_oid_pair_concurrently() -> Result<(), Box<dyn std::error::Error>> {
        let fixture = fixture()?;
        let cas = tempdir()?;
        let transport = ConcurrentTransport {
            barrier: Barrier::new(2),
            active: AtomicUsize::new(0),
            maximum_active: AtomicUsize::new(0),
        };
        let broker = Broker {
            trust_policy: &fixture.trust_policy,
            attestation: &fixture.attestation,
            authorization: &fixture.authorization,
            credentials: &fixture.credentials,
            cas_root: cas.path(),
            result_signing_key: &fixture.result_key,
            transport: &transport,
        };
        let first = proposal(&fixture);
        let mut second = first.clone();
        second.effect_id = "e".repeat(32);
        second.effect_sequence = 2;
        let batch = ConcurrentEffectExecutionProposal {
            contract: ConcurrentEffectExecutionProposal::CONTRACT.to_owned(),
            version: "1".to_owned(),
            proposals: vec![first, second],
        };
        let response = broker.execute_concurrent_proposals(&batch, fixture.now)?;

        assert_eq!(response.responses.len(), 2);
        assert_eq!(transport.maximum_active.load(Ordering::SeqCst), 2);

        Ok(())
    }
}
