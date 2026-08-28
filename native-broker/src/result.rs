use base64::Engine;
use base64::engine::general_purpose::STANDARD;
use ed25519_dalek::SigningKey;
use serde::{Deserialize, Serialize};
use time::{Duration, OffsetDateTime};

use crate::canonical;
use crate::crypto::{hmac_sha256_hex, sha256_hex, sign_base64};
use crate::protocol::{LiveEffectDescriptor, WIRE_VERSION};
use crate::trust::{
    NativeBrokerTrustPolicyEnvelope, NativeSupervisorAttestationEnvelope, SignedDocument,
    format_utc_microsecond, strict_utc_microsecond,
};
use crate::{BrokerError, BrokerResult};

#[derive(Clone, Copy, Debug, Deserialize, Eq, PartialEq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum EffectDisposition {
    Applied,
    PossiblyApplied,
    Denied,
    AlreadyConsumed,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct BrokeredEffectExecutionResultEnvelope {
    pub contract: String,
    pub version: String,
    pub algorithm: String,
    pub signer_id: String,
    pub issued_at: String,
    pub expires_at: String,
    pub launch_manifest_sha256: String,
    pub run_nonce: String,
    pub authorization_set_sha256: String,
    pub broker_policy_sha256: String,
    pub supervisor_attestation_sha256: String,
    pub effect_descriptor_sha256: String,
    pub effect_id: String,
    pub cas_record_sha256: String,
    pub disposition: EffectDisposition,
    pub request_started_at: Option<String>,
    pub response_received_at: Option<String>,
    pub http_status: u16,
    pub content_type: Option<String>,
    pub provider_request_id_hmac_sha256: Option<String>,
    pub response_body_base64: String,
    pub response_body_sha256: String,
    pub response_size_bytes: usize,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct BrokeredEffectExecutionReceiptEnvelope {
    pub contract: String,
    pub version: String,
    pub algorithm: String,
    pub signer_id: String,
    pub issued_at: String,
    pub expires_at: String,
    pub descriptor: LiveEffectDescriptor,
    pub cas_record_sha256: String,
    pub disposition: EffectDisposition,
    pub request_started_at: Option<String>,
    pub response_received_at: Option<String>,
    pub http_status: u16,
    pub content_type: Option<String>,
    pub provider_request_id_hmac_sha256: Option<String>,
    pub response_body_sha256: String,
    pub response_size_bytes: usize,
}

impl BrokeredEffectExecutionReceiptEnvelope {
    pub const CONTRACT: &'static str = "cieplik206.fakturownia.brokered-effect-execution-receipt";

    pub fn issue(
        trust_policy: &NativeBrokerTrustPolicyEnvelope,
        signing_key: &SigningKey,
        descriptor: LiveEffectDescriptor,
        result: &BrokeredEffectExecutionResultEnvelope,
        now: OffsetDateTime,
    ) -> BrokerResult<SignedDocument<Self>> {
        let envelope = Self {
            contract: Self::CONTRACT.to_owned(),
            version: WIRE_VERSION.to_owned(),
            algorithm: "Ed25519".to_owned(),
            signer_id: trust_policy.effect_result_signer.id.clone(),
            issued_at: format_utc_microsecond(now)?,
            expires_at: result.expires_at.clone(),
            descriptor,
            cas_record_sha256: result.cas_record_sha256.clone(),
            disposition: result.disposition,
            request_started_at: result.request_started_at.clone(),
            response_received_at: result.response_received_at.clone(),
            http_status: result.http_status,
            content_type: result.content_type.clone(),
            provider_request_id_hmac_sha256: result.provider_request_id_hmac_sha256.clone(),
            response_body_sha256: result.response_body_sha256.clone(),
            response_size_bytes: result.response_size_bytes,
        };
        envelope.validate(result)?;
        let signature = sign_base64(signing_key, &canonical::encode(&envelope)?);

        Ok(SignedDocument {
            envelope,
            signature,
        })
    }

    pub fn validate(&self, result: &BrokeredEffectExecutionResultEnvelope) -> BrokerResult<()> {
        if self.contract != Self::CONTRACT
            || self.version != WIRE_VERSION
            || self.algorithm != "Ed25519"
            || self.signer_id != result.signer_id
            || self.descriptor.effect_id != result.effect_id
            || sha256_hex(&canonical::encode(&self.descriptor)?) != result.effect_descriptor_sha256
            || self.descriptor.launch_manifest_sha256 != result.launch_manifest_sha256
            || self.descriptor.authorization_set_sha256 != result.authorization_set_sha256
            || self.descriptor.broker_policy_sha256 != result.broker_policy_sha256
            || self.descriptor.supervisor_attestation_sha256 != result.supervisor_attestation_sha256
            || self.cas_record_sha256 != result.cas_record_sha256
            || self.disposition != result.disposition
            || self.request_started_at != result.request_started_at
            || self.response_received_at != result.response_received_at
            || self.http_status != result.http_status
            || self.content_type != result.content_type
            || self.provider_request_id_hmac_sha256 != result.provider_request_id_hmac_sha256
            || self.response_body_sha256 != result.response_body_sha256
            || self.response_size_bytes != result.response_size_bytes
        {
            return Err(BrokerError::denied(
                "broker effect receipt does not bind its exact execution",
            ));
        }

        Ok(())
    }
}

impl BrokeredEffectExecutionResultEnvelope {
    pub const CONTRACT: &'static str = "cieplik206.fakturownia.brokered-effect-execution-result";

    #[allow(clippy::too_many_arguments)]
    pub fn issue(
        trust_policy: &NativeBrokerTrustPolicyEnvelope,
        attestation: &SignedDocument<NativeSupervisorAttestationEnvelope>,
        signing_key: &SigningKey,
        effect_descriptor_sha256: String,
        effect_id: String,
        cas_record_sha256: String,
        observation: ExecutionObservation,
        request_id_hmac_key: &[u8],
        now: OffsetDateTime,
    ) -> BrokerResult<SignedDocument<Self>> {
        let attestation_expires_at = strict_utc_microsecond(&attestation.envelope.expires_at)?;
        let expires_at = std::cmp::min(now + Duration::minutes(10), attestation_expires_at);
        let (
            disposition,
            request_started_at,
            response_received_at,
            http_status,
            content_type,
            request_id,
            response_body,
        ) = observation.into_parts();
        let provider_request_id_hmac_sha256 = request_id
            .as_deref()
            .map(|value| hmac_sha256_hex(request_id_hmac_key, value.as_bytes()))
            .transpose()?;
        let envelope = Self {
            contract: Self::CONTRACT.to_owned(),
            version: WIRE_VERSION.to_owned(),
            algorithm: "Ed25519".to_owned(),
            signer_id: trust_policy.effect_result_signer.id.clone(),
            issued_at: format_utc_microsecond(now)?,
            expires_at: format_utc_microsecond(expires_at)?,
            launch_manifest_sha256: attestation.envelope.launch_manifest_sha256.clone(),
            run_nonce: attestation.envelope.run_nonce.clone(),
            authorization_set_sha256: attestation.envelope.authorization_set_sha256.clone(),
            broker_policy_sha256: attestation.envelope.broker_policy_sha256.clone(),
            supervisor_attestation_sha256: attestation.sha256()?,
            effect_descriptor_sha256,
            effect_id,
            cas_record_sha256,
            disposition,
            request_started_at: request_started_at.map(format_utc_microsecond).transpose()?,
            response_received_at: response_received_at
                .map(format_utc_microsecond)
                .transpose()?,
            http_status,
            content_type,
            provider_request_id_hmac_sha256,
            response_body_base64: STANDARD.encode(&response_body),
            response_body_sha256: sha256_hex(&response_body),
            response_size_bytes: response_body.len(),
        };
        envelope.validate()?;
        let signature = sign_base64(signing_key, &canonical::encode(&envelope)?);

        Ok(SignedDocument {
            envelope,
            signature,
        })
    }

    pub fn validate(&self) -> BrokerResult<()> {
        if self.contract != Self::CONTRACT
            || self.version != WIRE_VERSION
            || self.algorithm != "Ed25519"
        {
            return Err(BrokerError::denied("broker result contract is invalid"));
        }

        let body = STANDARD
            .decode(&self.response_body_base64)
            .map_err(|_| BrokerError::denied("broker result body is invalid base64"))?;

        if STANDARD.encode(&body) != self.response_body_base64
            || body.len() != self.response_size_bytes
            || sha256_hex(&body) != self.response_body_sha256
        {
            return Err(BrokerError::denied("broker result body binding is invalid"));
        }

        match self.disposition {
            EffectDisposition::Applied => {
                if self.request_started_at.is_none()
                    || self.response_received_at.is_none()
                    || !(100..=599).contains(&self.http_status)
                {
                    return Err(BrokerError::denied(
                        "applied broker result has no bounded response",
                    ));
                }
            }
            EffectDisposition::PossiblyApplied => {
                if self.request_started_at.is_none()
                    || self.response_received_at.is_some()
                    || self.http_status != 0
                    || !body.is_empty()
                    || self.content_type.is_some()
                    || self.provider_request_id_hmac_sha256.is_some()
                {
                    return Err(BrokerError::denied(
                        "possibly-applied broker result overclaims evidence",
                    ));
                }
            }
            EffectDisposition::Denied | EffectDisposition::AlreadyConsumed => {
                if self.request_started_at.is_some()
                    || self.response_received_at.is_some()
                    || self.http_status != 0
                    || !body.is_empty()
                    || self.content_type.is_some()
                    || self.provider_request_id_hmac_sha256.is_some()
                {
                    return Err(BrokerError::denied(
                        "non-executed broker result overclaims evidence",
                    ));
                }
            }
        }

        Ok(())
    }
}

#[derive(Clone, Debug)]
pub enum ExecutionObservation {
    Applied {
        request_started_at: OffsetDateTime,
        response_received_at: OffsetDateTime,
        http_status: u16,
        content_type: Option<String>,
        provider_request_id: Option<String>,
        response_body: Vec<u8>,
    },
    PossiblyApplied {
        request_started_at: OffsetDateTime,
    },
    Denied,
    AlreadyConsumed,
}

type ObservationParts = (
    EffectDisposition,
    Option<OffsetDateTime>,
    Option<OffsetDateTime>,
    u16,
    Option<String>,
    Option<String>,
    Vec<u8>,
);

impl ExecutionObservation {
    fn into_parts(self) -> ObservationParts {
        match self {
            Self::Applied {
                request_started_at,
                response_received_at,
                http_status,
                content_type,
                provider_request_id,
                response_body,
            } => (
                EffectDisposition::Applied,
                Some(request_started_at),
                Some(response_received_at),
                http_status,
                content_type,
                provider_request_id,
                response_body,
            ),
            Self::PossiblyApplied { request_started_at } => (
                EffectDisposition::PossiblyApplied,
                Some(request_started_at),
                None,
                0,
                None,
                None,
                Vec::new(),
            ),
            Self::Denied => (
                EffectDisposition::Denied,
                None,
                None,
                0,
                None,
                None,
                Vec::new(),
            ),
            Self::AlreadyConsumed => (
                EffectDisposition::AlreadyConsumed,
                None,
                None,
                0,
                None,
                None,
                Vec::new(),
            ),
        }
    }
}
