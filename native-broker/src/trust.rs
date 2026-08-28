use std::collections::BTreeSet;

use ed25519_dalek::{SigningKey, VerifyingKey};
use serde::{Deserialize, Serialize};
use time::{Duration, OffsetDateTime};

use crate::canonical;
use crate::crypto::{
    decode_canonical_base64, sha256_hex, sign_base64, verify_base64, verifying_key,
};
use crate::protocol::WIRE_VERSION;
use crate::{BrokerError, BrokerResult};

const ALGORITHM: &str = "Ed25519";

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct SignedDocument<T> {
    pub envelope: T,
    pub signature: String,
}

impl<T: Serialize> SignedDocument<T> {
    pub fn canonical_envelope(&self) -> BrokerResult<Vec<u8>> {
        canonical::encode(&self.envelope)
    }

    pub fn canonical(&self) -> BrokerResult<Vec<u8>> {
        canonical::encode(self)
    }

    pub fn sha256(&self) -> BrokerResult<String> {
        Ok(sha256_hex(&self.canonical()?))
    }
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct RoleSigner {
    pub id: String,
    pub algorithm: String,
    pub public_key: String,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct NativeBrokerTrustPolicyEnvelope {
    pub contract: String,
    pub version: String,
    pub algorithm: String,
    pub signer_id: String,
    pub issued_at: String,
    pub expires_at: String,
    pub broker_policy_sha256: String,
    pub supervisor_semantics_sha256: String,
    pub argv_sha256: String,
    pub environment_sha256: String,
    pub probe_uid: u32,
    pub probe_gid: u32,
    pub supervisor_signer: RoleSigner,
    pub effect_result_signer: RoleSigner,
}

impl NativeBrokerTrustPolicyEnvelope {
    pub const CONTRACT: &'static str = "cieplik206.fakturownia.native-broker-trust-policy";

    pub fn verify(
        document: &SignedDocument<Self>,
        expected_signer_id: &str,
        policy_public_key: &VerifyingKey,
        observed_at: OffsetDateTime,
    ) -> BrokerResult<()> {
        let policy = &document.envelope;
        policy.validate(observed_at)?;

        if policy.contract != Self::CONTRACT
            || policy.version != WIRE_VERSION
            || policy.algorithm != ALGORITHM
            || policy.signer_id != expected_signer_id
        {
            return Err(BrokerError::denied(
                "native broker trust policy identity is invalid",
            ));
        }

        verify_base64(
            policy_public_key,
            &document.canonical_envelope()?,
            &document.signature,
        )
    }

    pub fn supervisor_verifying_key(&self) -> BrokerResult<VerifyingKey> {
        Self::validate_role(&self.supervisor_signer)
    }

    pub fn result_verifying_key(&self) -> BrokerResult<VerifyingKey> {
        Self::validate_role(&self.effect_result_signer)
    }

    fn validate(&self, observed_at: OffsetDateTime) -> BrokerResult<()> {
        let issued_at = strict_utc_microsecond(&self.issued_at)?;
        let expires_at = strict_utc_microsecond(&self.expires_at)?;

        if issued_at > observed_at
            || expires_at <= observed_at
            || expires_at - issued_at > Duration::days(365)
        {
            return Err(BrokerError::denied(
                "native broker trust policy is outside its validity window",
            ));
        }

        validate_identifier(&self.signer_id)?;

        for digest in [
            &self.broker_policy_sha256,
            &self.supervisor_semantics_sha256,
            &self.argv_sha256,
            &self.environment_sha256,
        ] {
            validate_sha256(digest)?;
        }

        if self.probe_uid == 0 || self.probe_gid == 0 {
            return Err(BrokerError::denied(
                "native broker probe identity must be unprivileged",
            ));
        }

        let supervisor_key = self.supervisor_verifying_key()?;
        let result_key = self.result_verifying_key()?;

        if self.supervisor_signer.id == self.effect_result_signer.id || supervisor_key == result_key
        {
            return Err(BrokerError::denied(
                "native broker signing roles must be disjoint",
            ));
        }

        Ok(())
    }

    fn validate_role(signer: &RoleSigner) -> BrokerResult<VerifyingKey> {
        validate_identifier(&signer.id)?;

        if signer.algorithm != ALGORITHM {
            return Err(BrokerError::denied(
                "native broker role uses an unsupported algorithm",
            ));
        }

        verifying_key(&signer.public_key)
    }
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct NativeSupervisorAttestationEnvelope {
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
    pub supervisor_semantics_sha256: String,
    pub argv_sha256: String,
    pub environment_sha256: String,
    pub probe_uid: u32,
    pub probe_gid: u32,
}

impl NativeSupervisorAttestationEnvelope {
    pub const CONTRACT: &'static str = "cieplik206.fakturownia.native-supervisor-attestation";

    #[allow(clippy::too_many_arguments)]
    pub fn issue(
        policy: &NativeBrokerTrustPolicyEnvelope,
        signing_key: &SigningKey,
        launch_manifest_sha256: String,
        run_nonce: String,
        authorization_set_sha256: String,
        authorization_bundle_sha256: String,
        probe_plan_sha256: String,
        issued_at: OffsetDateTime,
    ) -> BrokerResult<SignedDocument<Self>> {
        let envelope = Self {
            contract: Self::CONTRACT.to_owned(),
            version: WIRE_VERSION.to_owned(),
            algorithm: ALGORITHM.to_owned(),
            signer_id: policy.supervisor_signer.id.clone(),
            issued_at: format_utc_microsecond(issued_at)?,
            expires_at: format_utc_microsecond(issued_at + Duration::minutes(10))?,
            launch_manifest_sha256,
            run_nonce,
            authorization_set_sha256,
            authorization_bundle_sha256,
            probe_plan_sha256,
            broker_policy_sha256: policy.broker_policy_sha256.clone(),
            supervisor_semantics_sha256: policy.supervisor_semantics_sha256.clone(),
            argv_sha256: policy.argv_sha256.clone(),
            environment_sha256: policy.environment_sha256.clone(),
            probe_uid: policy.probe_uid,
            probe_gid: policy.probe_gid,
        };
        envelope.validate(policy, issued_at)?;
        let signature = sign_base64(signing_key, &canonical::encode(&envelope)?);

        Ok(SignedDocument {
            envelope,
            signature,
        })
    }

    pub fn validate(
        &self,
        policy: &NativeBrokerTrustPolicyEnvelope,
        observed_at: OffsetDateTime,
    ) -> BrokerResult<()> {
        if self.contract != Self::CONTRACT
            || self.version != WIRE_VERSION
            || self.algorithm != ALGORITHM
            || self.signer_id != policy.supervisor_signer.id
            || self.broker_policy_sha256 != policy.broker_policy_sha256
            || self.supervisor_semantics_sha256 != policy.supervisor_semantics_sha256
            || self.argv_sha256 != policy.argv_sha256
            || self.environment_sha256 != policy.environment_sha256
            || self.probe_uid != policy.probe_uid
            || self.probe_gid != policy.probe_gid
        {
            return Err(BrokerError::denied(
                "native supervisor attestation does not match policy",
            ));
        }

        validate_identifier(&self.signer_id)?;

        for digest in [
            &self.launch_manifest_sha256,
            &self.authorization_set_sha256,
            &self.authorization_bundle_sha256,
            &self.probe_plan_sha256,
            &self.broker_policy_sha256,
            &self.supervisor_semantics_sha256,
            &self.argv_sha256,
            &self.environment_sha256,
        ] {
            validate_sha256(digest)?;
        }

        decode_canonical_base64(&self.run_nonce, 32)?;
        let issued_at = strict_utc_microsecond(&self.issued_at)?;
        let expires_at = strict_utc_microsecond(&self.expires_at)?;

        if issued_at > observed_at
            || expires_at <= observed_at
            || expires_at - issued_at > Duration::minutes(10)
        {
            return Err(BrokerError::denied(
                "native supervisor attestation is outside its validity window",
            ));
        }

        Ok(())
    }
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct AuthorizationSigner {
    pub id: String,
    pub public_key: String,
}

pub fn assert_disjoint_keys(keys: &[AuthorizationSigner]) -> BrokerResult<()> {
    if keys.is_empty() || keys.len() > 16 {
        return Err(BrokerError::denied(
            "native broker authorization signer set is invalid",
        ));
    }

    let mut ids = BTreeSet::new();
    let mut fingerprints = BTreeSet::new();

    for key in keys {
        validate_identifier(&key.id)?;
        let public_key = verifying_key(&key.public_key)?;

        if !ids.insert(key.id.as_str()) || !fingerprints.insert(sha256_hex(public_key.as_bytes())) {
            return Err(BrokerError::denied(
                "native broker authorization signer set contains duplicates",
            ));
        }
    }

    Ok(())
}

pub fn validate_identifier(value: &str) -> BrokerResult<()> {
    if value.is_empty()
        || value.len() > 64
        || !value.bytes().enumerate().all(|(index, byte)| {
            byte.is_ascii_lowercase()
                || byte.is_ascii_digit()
                || (index > 0 && matches!(byte, b'.' | b'_' | b'-'))
        })
    {
        return Err(BrokerError::denied("native broker identifier is invalid"));
    }

    Ok(())
}

pub fn validate_sha256(value: &str) -> BrokerResult<()> {
    if value.len() != 64
        || !value
            .bytes()
            .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase())
    {
        return Err(BrokerError::denied("native broker SHA-256 is invalid"));
    }

    Ok(())
}

pub fn strict_utc_microsecond(value: &str) -> BrokerResult<OffsetDateTime> {
    if value.len() != 27
        || value.as_bytes().get(19) != Some(&b'.')
        || value.as_bytes().get(26) != Some(&b'Z')
    {
        return Err(BrokerError::denied(
            "native broker timestamp is not a strict UTC microsecond instant",
        ));
    }

    OffsetDateTime::parse(value, &time::format_description::well_known::Rfc3339).map_err(|_| {
        BrokerError::denied("native broker timestamp is not a strict UTC microsecond instant")
    })
}

pub fn format_utc_microsecond(value: OffsetDateTime) -> BrokerResult<String> {
    let value = value.to_offset(time::UtcOffset::UTC);
    let month = u8::from(value.month());

    Ok(format!(
        "{:04}-{month:02}-{:02}T{:02}:{:02}:{:02}.{:06}Z",
        value.year(),
        value.day(),
        value.hour(),
        value.minute(),
        value.second(),
        value.microsecond(),
    ))
}

#[cfg(test)]
mod tests {
    use base64::Engine;
    use base64::engine::general_purpose::STANDARD;
    use ed25519_dalek::SigningKey;
    use rand::rngs::OsRng;
    use time::OffsetDateTime;

    use crate::canonical;
    use crate::crypto::sign_base64;

    use super::{
        NativeBrokerTrustPolicyEnvelope, NativeSupervisorAttestationEnvelope, RoleSigner,
        SignedDocument, format_utc_microsecond,
    };

    fn trust_policy(
        policy_key: &SigningKey,
        supervisor_key: &SigningKey,
        result_key: &SigningKey,
        now: OffsetDateTime,
    ) -> Result<SignedDocument<NativeBrokerTrustPolicyEnvelope>, Box<dyn std::error::Error>> {
        let envelope = NativeBrokerTrustPolicyEnvelope {
            contract: NativeBrokerTrustPolicyEnvelope::CONTRACT.to_owned(),
            version: "1".to_owned(),
            algorithm: "Ed25519".to_owned(),
            signer_id: "deployment-policy-1".to_owned(),
            issued_at: format_utc_microsecond(now)?,
            expires_at: format_utc_microsecond(now + time::Duration::hours(1))?,
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
        let signature = sign_base64(policy_key, &canonical::encode(&envelope)?);

        Ok(SignedDocument {
            envelope,
            signature,
        })
    }

    #[test]
    fn verifies_policy_and_issues_a_bound_attestation() -> Result<(), Box<dyn std::error::Error>> {
        let policy_key = SigningKey::generate(&mut OsRng);
        let supervisor_key = SigningKey::generate(&mut OsRng);
        let result_key = SigningKey::generate(&mut OsRng);
        let now = OffsetDateTime::now_utc();
        let policy = trust_policy(&policy_key, &supervisor_key, &result_key, now)?;

        NativeBrokerTrustPolicyEnvelope::verify(
            &policy,
            "deployment-policy-1",
            &policy_key.verifying_key(),
            now,
        )?;
        let attestation = NativeSupervisorAttestationEnvelope::issue(
            &policy.envelope,
            &supervisor_key,
            "a".repeat(64),
            STANDARD.encode([1_u8; 32]),
            "b".repeat(64),
            "c".repeat(64),
            "d".repeat(64),
            now,
        )?;

        attestation.envelope.validate(&policy.envelope, now)?;
        crate::crypto::verify_base64(
            &policy.envelope.supervisor_verifying_key()?,
            &attestation.canonical_envelope()?,
            &attestation.signature,
        )?;

        Ok(())
    }

    #[test]
    fn rejects_role_confusion() -> Result<(), Box<dyn std::error::Error>> {
        let policy_key = SigningKey::generate(&mut OsRng);
        let shared_key = SigningKey::generate(&mut OsRng);
        let now = OffsetDateTime::now_utc();
        let policy = trust_policy(&policy_key, &shared_key, &shared_key, now)?;

        assert!(
            NativeBrokerTrustPolicyEnvelope::verify(
                &policy,
                "deployment-policy-1",
                &policy_key.verifying_key(),
                now,
            )
            .is_err()
        );

        Ok(())
    }
}
