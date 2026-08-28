use base64::Engine;
use base64::engine::general_purpose::STANDARD;
use ed25519_dalek::SigningKey;
use serde::{Deserialize, Serialize};
use time::{Duration, OffsetDateTime};

use crate::canonical;
use crate::crypto::{hmac_sha256_hex, sha256_hex, sign_base64};
use crate::protocol::{ReadObservationProposal, WIRE_VERSION};
use crate::trust::{
    NativeBrokerTrustPolicyEnvelope, NativeSupervisorAttestationEnvelope, SignedDocument,
    format_utc_microsecond,
};
use crate::{BrokerError, BrokerResult};

#[derive(Clone, Copy, Debug, Deserialize, Eq, PartialEq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum ReadObservationDisposition {
    Observed,
    TransportFailure,
}

#[derive(Clone, Debug)]
pub enum ReadExecutionObservation {
    Observed {
        request_started_at: OffsetDateTime,
        response_received_at: OffsetDateTime,
        http_status: u16,
        content_type: Option<String>,
        provider_request_id: Option<String>,
        response_body: Vec<u8>,
    },
    TransportFailure {
        request_started_at: OffsetDateTime,
    },
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct BrokeredReadObservationResultEnvelope {
    pub contract: String,
    pub version: String,
    pub algorithm: String,
    pub signer_id: String,
    pub issued_at: String,
    pub expires_at: String,
    pub launch_manifest_sha256: String,
    pub run_nonce: String,
    pub authorization_set_sha256: String,
    pub authorization_bundle_sha256: String,
    pub probe_plan_sha256: String,
    pub broker_policy_sha256: String,
    pub supervisor_attestation_sha256: String,
    pub proposal_sha256: String,
    pub observation_id: String,
    pub disposition: ReadObservationDisposition,
    pub request_started_at: String,
    pub response_received_at: Option<String>,
    pub http_status: u16,
    pub content_type: Option<String>,
    pub provider_request_id_hmac_sha256: Option<String>,
    pub response_body_base64: String,
    pub response_body_sha256: String,
    pub response_size_bytes: usize,
}

impl BrokeredReadObservationResultEnvelope {
    pub const CONTRACT: &'static str = "cieplik206.fakturownia.brokered-read-observation-result";

    #[allow(clippy::too_many_arguments)]
    pub fn issue(
        trust_policy: &NativeBrokerTrustPolicyEnvelope,
        attestation: &SignedDocument<NativeSupervisorAttestationEnvelope>,
        signing_key: &SigningKey,
        proposal: &ReadObservationProposal,
        observation: ReadExecutionObservation,
        request_id_hmac_key: &[u8],
        now: OffsetDateTime,
    ) -> BrokerResult<SignedDocument<Self>> {
        let (
            disposition,
            request_started_at,
            response_received_at,
            http_status,
            content_type,
            provider_request_id,
            response_body,
        ) = match observation {
            ReadExecutionObservation::Observed {
                request_started_at,
                response_received_at,
                http_status,
                content_type,
                provider_request_id,
                response_body,
            } => (
                ReadObservationDisposition::Observed,
                request_started_at,
                Some(response_received_at),
                http_status,
                content_type,
                provider_request_id,
                response_body,
            ),
            ReadExecutionObservation::TransportFailure { request_started_at } => (
                ReadObservationDisposition::TransportFailure,
                request_started_at,
                None,
                0,
                None,
                None,
                Vec::new(),
            ),
        };
        let envelope = Self {
            contract: Self::CONTRACT.to_owned(),
            version: WIRE_VERSION.to_owned(),
            algorithm: "Ed25519".to_owned(),
            signer_id: trust_policy.effect_result_signer.id.clone(),
            issued_at: format_utc_microsecond(now)?,
            expires_at: format_utc_microsecond(now + Duration::minutes(10))?,
            launch_manifest_sha256: attestation.envelope.launch_manifest_sha256.clone(),
            run_nonce: attestation.envelope.run_nonce.clone(),
            authorization_set_sha256: attestation.envelope.authorization_set_sha256.clone(),
            authorization_bundle_sha256: attestation.envelope.authorization_bundle_sha256.clone(),
            probe_plan_sha256: attestation.envelope.probe_plan_sha256.clone(),
            broker_policy_sha256: attestation.envelope.broker_policy_sha256.clone(),
            supervisor_attestation_sha256: attestation.sha256()?,
            proposal_sha256: sha256_hex(&canonical::encode(proposal)?),
            observation_id: proposal.observation_id.clone(),
            disposition,
            request_started_at: format_utc_microsecond(request_started_at)?,
            response_received_at: response_received_at
                .map(format_utc_microsecond)
                .transpose()?,
            http_status,
            content_type,
            provider_request_id_hmac_sha256: provider_request_id
                .as_deref()
                .map(|value| hmac_sha256_hex(request_id_hmac_key, value.as_bytes()))
                .transpose()?,
            response_body_base64: STANDARD.encode(&response_body),
            response_body_sha256: sha256_hex(&response_body),
            response_size_bytes: response_body.len(),
        };
        envelope.validate()?;

        Ok(SignedDocument {
            signature: sign_base64(signing_key, &canonical::encode(&envelope)?),
            envelope,
        })
    }

    pub fn validate(&self) -> BrokerResult<()> {
        if self.contract != Self::CONTRACT
            || self.version != WIRE_VERSION
            || self.algorithm != "Ed25519"
            || self.observation_id.len() != 32
            || !self
                .observation_id
                .bytes()
                .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase())
        {
            return Err(BrokerError::denied(
                "broker read observation result contract is invalid",
            ));
        }

        for digest in [
            &self.launch_manifest_sha256,
            &self.authorization_set_sha256,
            &self.authorization_bundle_sha256,
            &self.probe_plan_sha256,
            &self.broker_policy_sha256,
            &self.supervisor_attestation_sha256,
            &self.proposal_sha256,
            &self.response_body_sha256,
        ] {
            crate::trust::validate_sha256(digest)?;
        }

        let body = STANDARD
            .decode(&self.response_body_base64)
            .map_err(|_| BrokerError::denied("broker read body is invalid base64"))?;

        if STANDARD.encode(&body) != self.response_body_base64
            || body.len() != self.response_size_bytes
            || sha256_hex(&body) != self.response_body_sha256
        {
            return Err(BrokerError::denied(
                "broker read observation body binding is invalid",
            ));
        }

        match self.disposition {
            ReadObservationDisposition::Observed => {
                if self.response_received_at.is_none() || !(100..=599).contains(&self.http_status) {
                    return Err(BrokerError::denied(
                        "broker read observation has no bounded response",
                    ));
                }
            }
            ReadObservationDisposition::TransportFailure => {
                if self.response_received_at.is_some()
                    || self.http_status != 0
                    || !body.is_empty()
                    || self.content_type.is_some()
                    || self.provider_request_id_hmac_sha256.is_some()
                {
                    return Err(BrokerError::denied(
                        "broker read transport failure overclaims evidence",
                    ));
                }
            }
        }

        Ok(())
    }
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct BrokeredReadObservationResponse {
    pub contract: String,
    pub version: String,
    pub result: SignedDocument<BrokeredReadObservationResultEnvelope>,
}

impl BrokeredReadObservationResponse {
    pub const CONTRACT: &'static str = "cieplik206.fakturownia.brokered-read-observation-response";
}
