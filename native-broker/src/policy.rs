use std::fs::File;
use std::io::Read;
use std::os::unix::fs::MetadataExt;
use std::path::{Path, PathBuf};

use ed25519_dalek::VerifyingKey;
use serde::{Deserialize, Serialize};
use time::{Duration, OffsetDateTime};

use crate::authorization::ConsumptionAuthorityTrust;
use crate::canonical;
use crate::crypto::{constant_time_hex_equal, sha256_hex, verify_base64};
use crate::trust::{
    AuthorizationSigner, SignedDocument, assert_disjoint_keys, strict_utc_microsecond,
    validate_identifier, validate_sha256,
};
use crate::{BrokerError, BrokerResult};

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct NativeSupervisorPolicyEnvelope {
    pub contract: String,
    pub version: String,
    pub algorithm: String,
    pub signer_id: String,
    pub issued_at: String,
    pub expires_at: String,
    pub supervisor_executable_path: String,
    pub supervisor_executable_sha256: String,
    pub supervisor_semantics_sha256: String,
    pub php_executable_path: String,
    pub php_executable_sha256: String,
    pub launcher_path: String,
    pub launcher_sha256: String,
    pub launcher_policy_path: String,
    pub launcher_policy_sha256: String,
    pub launch_manifest_sha256: String,
    pub probe_uid: u32,
    pub probe_gid: u32,
    pub trust_policy_path: String,
    pub trust_policy_sha256: String,
    pub trust_policy_signer_id: String,
    pub trust_policy_public_key: String,
    pub authorization_bundle_path: String,
    pub authorization_bundle_sha256: String,
    pub maximum_authorization_ttl_seconds: u32,
    pub provider_credential_path: String,
    pub provider_credential_sha256: String,
    pub supervisor_signing_seed_path: String,
    pub supervisor_signing_seed_sha256: String,
    pub effect_result_signing_seed_path: String,
    pub effect_result_signing_seed_sha256: String,
    pub cas_root: String,
    pub argv_sha256: String,
    pub environment_sha256: String,
    pub authorization_signers: Vec<AuthorizationSigner>,
    pub consumption_authority_id: String,
    pub consumption_authority_public_key: String,
    pub consumption_authority_policy_sha256: String,
    pub consumption_store_id: String,
    pub consumption_store_identity_sha256: String,
    pub maximum_consumption_receipt_ttl_seconds: u32,
}

impl NativeSupervisorPolicyEnvelope {
    pub const CONTRACT: &'static str = "cieplik206.fakturownia.native-supervisor-policy";

    pub fn verify(
        document: &SignedDocument<Self>,
        expected_signer_id: &str,
        policy_public_key: &VerifyingKey,
        observed_at: OffsetDateTime,
        compiled_semantics_sha256: &str,
    ) -> BrokerResult<()> {
        let policy = &document.envelope;
        policy.validate(observed_at, compiled_semantics_sha256)?;

        if policy.signer_id != expected_signer_id {
            return Err(BrokerError::denied(
                "native supervisor policy signer is not pinned",
            ));
        }

        verify_base64(
            policy_public_key,
            &document.canonical_envelope()?,
            &document.signature,
        )
    }

    #[must_use]
    pub fn expected_argv(&self) -> Vec<String> {
        vec![
            self.php_executable_path.clone(),
            "-n".to_owned(),
            self.launcher_path.clone(),
            "--supervised".to_owned(),
        ]
    }

    pub fn expected_environment(&self) -> Vec<(String, String)> {
        let php_directory = Path::new(&self.php_executable_path)
            .parent()
            .map_or_else(String::new, |path| path.to_string_lossy().into_owned());

        vec![
            ("LANG".to_owned(), "C".to_owned()),
            ("LC_ALL".to_owned(), "C".to_owned()),
            ("PATH".to_owned(), php_directory),
        ]
    }

    #[must_use]
    pub fn consumption_authority_trust(&self) -> ConsumptionAuthorityTrust {
        ConsumptionAuthorityTrust {
            authority_id: self.consumption_authority_id.clone(),
            public_key: self.consumption_authority_public_key.clone(),
            policy_sha256: self.consumption_authority_policy_sha256.clone(),
            store_id: self.consumption_store_id.clone(),
            store_identity_sha256: self.consumption_store_identity_sha256.clone(),
            maximum_receipt_ttl_seconds: self.maximum_consumption_receipt_ttl_seconds,
        }
    }

    pub fn assert_prelaunch_assets(&self) -> BrokerResult<()> {
        let current_executable = std::env::current_exe()
            .map_err(|_| BrokerError::denied("cannot resolve native supervisor executable"))?;

        if current_executable != PathBuf::from(&self.supervisor_executable_path) {
            return Err(BrokerError::denied(
                "native supervisor executable path does not match deployment policy",
            ));
        }

        for (path, digest) in [
            (
                &self.supervisor_executable_path,
                &self.supervisor_executable_sha256,
            ),
            (&self.php_executable_path, &self.php_executable_sha256),
            (&self.launcher_path, &self.launcher_sha256),
            (&self.launcher_policy_path, &self.launcher_policy_sha256),
            (&self.trust_policy_path, &self.trust_policy_sha256),
            (
                &self.authorization_bundle_path,
                &self.authorization_bundle_sha256,
            ),
        ] {
            let bytes = read_trusted_file(Path::new(path), 16_777_216, false)?;

            if !constant_time_hex_equal(digest, &sha256_hex(&bytes)) {
                return Err(BrokerError::denied(
                    "native supervisor asset digest does not match policy",
                ));
            }
        }

        assert_trusted_directory(Path::new(&self.cas_root))
    }

    fn validate(
        &self,
        observed_at: OffsetDateTime,
        compiled_semantics_sha256: &str,
    ) -> BrokerResult<()> {
        if self.contract != Self::CONTRACT || self.version != "1" || self.algorithm != "Ed25519" {
            return Err(BrokerError::denied(
                "native supervisor policy contract is invalid",
            ));
        }

        validate_identifier(&self.signer_id)?;
        validate_identifier(&self.trust_policy_signer_id)?;
        validate_identifier(&self.consumption_authority_id)?;
        validate_identifier(&self.consumption_store_id)?;
        let issued_at = strict_utc_microsecond(&self.issued_at)?;
        let expires_at = strict_utc_microsecond(&self.expires_at)?;

        if issued_at > observed_at
            || expires_at <= observed_at
            || expires_at - issued_at > Duration::days(365)
        {
            return Err(BrokerError::denied(
                "native supervisor policy is outside its validity window",
            ));
        }

        for path in [
            &self.supervisor_executable_path,
            &self.php_executable_path,
            &self.launcher_path,
            &self.launcher_policy_path,
            &self.trust_policy_path,
            &self.authorization_bundle_path,
            &self.provider_credential_path,
            &self.supervisor_signing_seed_path,
            &self.effect_result_signing_seed_path,
            &self.cas_root,
        ] {
            validate_absolute_path(path)?;
        }

        for digest in [
            &self.supervisor_executable_sha256,
            &self.supervisor_semantics_sha256,
            &self.php_executable_sha256,
            &self.launcher_sha256,
            &self.launcher_policy_sha256,
            &self.launch_manifest_sha256,
            &self.trust_policy_sha256,
            &self.authorization_bundle_sha256,
            &self.provider_credential_sha256,
            &self.supervisor_signing_seed_sha256,
            &self.effect_result_signing_seed_sha256,
            &self.argv_sha256,
            &self.environment_sha256,
            &self.consumption_authority_policy_sha256,
            &self.consumption_store_identity_sha256,
        ] {
            validate_sha256(digest)?;
        }

        if !constant_time_hex_equal(&self.supervisor_semantics_sha256, compiled_semantics_sha256)
            || self.probe_uid == 0
            || self.probe_gid == 0
            || self.maximum_authorization_ttl_seconds == 0
            || self.maximum_authorization_ttl_seconds > 3_600
            || self.maximum_consumption_receipt_ttl_seconds == 0
            || self.maximum_consumption_receipt_ttl_seconds > 86_400
        {
            return Err(BrokerError::denied(
                "native supervisor policy semantics are invalid",
            ));
        }

        let argv_sha256 = sha256_hex(&canonical::encode(&serde_json::json!({
            "contract": "cieplik206.fakturownia.native-supervisor-argv",
            "version": "1",
            "value": self.expected_argv(),
        }))?);
        let environment_sha256 = sha256_hex(&canonical::encode(&serde_json::json!({
            "contract": "cieplik206.fakturownia.native-supervisor-environment",
            "version": "1",
            "value": self.expected_environment(),
        }))?);

        if !constant_time_hex_equal(&self.argv_sha256, &argv_sha256)
            || !constant_time_hex_equal(&self.environment_sha256, &environment_sha256)
        {
            return Err(BrokerError::denied(
                "native supervisor argv or environment contract is invalid",
            ));
        }

        let trust_policy_key = crate::crypto::verifying_key(&self.trust_policy_public_key)?;
        let consumption_authority_key =
            crate::crypto::verifying_key(&self.consumption_authority_public_key)?;
        assert_disjoint_keys(&self.authorization_signers)?;

        if trust_policy_key == consumption_authority_key
            || self.authorization_signers.iter().any(|signer| {
                crate::crypto::verifying_key(&signer.public_key)
                    .is_ok_and(|key| key == consumption_authority_key)
            })
        {
            return Err(BrokerError::denied(
                "native supervisor authority signing roles must be disjoint",
            ));
        }

        Ok(())
    }
}

pub fn read_trusted_file(path: &Path, maximum_bytes: usize, secret: bool) -> BrokerResult<Vec<u8>> {
    assert_trusted_ancestors(path)?;
    let metadata = std::fs::symlink_metadata(path)
        .map_err(|_| BrokerError::denied("native supervisor asset is unavailable"))?;
    let mode = metadata.mode();

    if !metadata.file_type().is_file()
        || metadata.nlink() != 1
        || metadata.uid() != 0
        || mode & 0o022 != 0
        || (secret && mode & 0o077 != 0)
        || metadata.len() == 0
        || metadata.len() > maximum_bytes as u64
    {
        return Err(BrokerError::denied(
            "native supervisor asset ownership or mode is invalid",
        ));
    }

    let mut file =
        File::open(path).map_err(|_| BrokerError::denied("cannot open native supervisor asset"))?;
    let opened = file
        .metadata()
        .map_err(|_| BrokerError::denied("cannot inspect opened native supervisor asset"))?;

    if metadata.dev() != opened.dev() || metadata.ino() != opened.ino() {
        return Err(BrokerError::denied(
            "native supervisor asset changed while opening",
        ));
    }

    let expected_length = usize::try_from(metadata.len())
        .map_err(|_| BrokerError::denied("native supervisor asset is too large"))?;
    let mut bytes = Vec::with_capacity(expected_length);
    file.read_to_end(&mut bytes)
        .map_err(|_| BrokerError::denied("cannot read complete native supervisor asset"))?;

    if bytes.len() != expected_length {
        return Err(BrokerError::denied(
            "native supervisor asset changed while reading",
        ));
    }

    Ok(bytes)
}

pub fn assert_trusted_directory(path: &Path) -> BrokerResult<()> {
    assert_trusted_ancestors(path)?;
    let metadata = std::fs::symlink_metadata(path)
        .map_err(|_| BrokerError::denied("native supervisor directory is unavailable"))?;

    if !metadata.file_type().is_dir() || metadata.uid() != 0 || metadata.mode() & 0o022 != 0 {
        return Err(BrokerError::denied(
            "native supervisor directory ownership or mode is invalid",
        ));
    }

    Ok(())
}

fn assert_trusted_ancestors(path: &Path) -> BrokerResult<()> {
    let parent = path
        .parent()
        .ok_or_else(|| BrokerError::denied("native supervisor path has no parent"))?;

    for ancestor in parent.ancestors() {
        let metadata = std::fs::symlink_metadata(ancestor)
            .map_err(|_| BrokerError::denied("native supervisor ancestor is unavailable"))?;

        if !metadata.file_type().is_dir() || metadata.uid() != 0 || metadata.mode() & 0o022 != 0 {
            return Err(BrokerError::denied(
                "native supervisor ancestor is not protected",
            ));
        }
    }

    Ok(())
}

fn validate_absolute_path(value: &str) -> BrokerResult<PathBuf> {
    if value.is_empty() || value.len() > 4_096 || !value.starts_with('/') {
        return Err(BrokerError::denied(
            "native supervisor policy path is invalid",
        ));
    }

    let path = PathBuf::from(value);

    if path.components().any(|component| {
        matches!(
            component,
            std::path::Component::CurDir | std::path::Component::ParentDir
        )
    }) {
        return Err(BrokerError::denied(
            "native supervisor policy path is not canonical",
        ));
    }

    Ok(path)
}

#[cfg(test)]
mod tests {
    use base64::Engine;
    use base64::engine::general_purpose::STANDARD;
    use ed25519_dalek::SigningKey;
    use rand::rngs::OsRng;
    use time::{Duration, OffsetDateTime};

    use crate::canonical;
    use crate::crypto::{sha256_hex, sign_base64};
    use crate::trust::{AuthorizationSigner, SignedDocument, format_utc_microsecond};

    use super::NativeSupervisorPolicyEnvelope;

    fn policy(
        policy_key: &SigningKey,
        authorization_key: &SigningKey,
        now: OffsetDateTime,
    ) -> Result<SignedDocument<NativeSupervisorPolicyEnvelope>, Box<dyn std::error::Error>> {
        let consumption_authority_key = SigningKey::generate(&mut OsRng);
        let mut envelope = NativeSupervisorPolicyEnvelope {
            contract: NativeSupervisorPolicyEnvelope::CONTRACT.to_owned(),
            version: "1".to_owned(),
            algorithm: "Ed25519".to_owned(),
            signer_id: "native-deployment-policy-1".to_owned(),
            issued_at: format_utc_microsecond(now - Duration::minutes(1))?,
            expires_at: format_utc_microsecond(now + Duration::hours(1))?,
            supervisor_executable_path: "/opt/cieplik206/bin/fakturownia-native-broker".to_owned(),
            supervisor_executable_sha256: "1".repeat(64),
            supervisor_semantics_sha256: "2".repeat(64),
            php_executable_path: "/usr/bin/php8.4".to_owned(),
            php_executable_sha256: "3".repeat(64),
            launcher_path: "/opt/cieplik206/fakturownia/launcher.php".to_owned(),
            launcher_sha256: "4".repeat(64),
            launcher_policy_path: "/etc/cieplik206/fakturownia/preautoload-policy.json".to_owned(),
            launcher_policy_sha256: "5".repeat(64),
            launch_manifest_sha256: "6".repeat(64),
            probe_uid: 991,
            probe_gid: 991,
            trust_policy_path: "/etc/cieplik206/fakturownia/trust-policy.json".to_owned(),
            trust_policy_sha256: "7".repeat(64),
            trust_policy_signer_id: "native-trust-policy-1".to_owned(),
            trust_policy_public_key: STANDARD.encode(policy_key.verifying_key().as_bytes()),
            authorization_bundle_path: "/run/cieplik206/fakturownia/authorization.json".to_owned(),
            authorization_bundle_sha256: "9".repeat(64),
            maximum_authorization_ttl_seconds: 360,
            provider_credential_path: "/run/cieplik206/fakturownia/credential.json".to_owned(),
            provider_credential_sha256: "a".repeat(64),
            supervisor_signing_seed_path: "/etc/cieplik206/fakturownia/supervisor.seed".to_owned(),
            supervisor_signing_seed_sha256: "b".repeat(64),
            effect_result_signing_seed_path: "/etc/cieplik206/fakturownia/result.seed".to_owned(),
            effect_result_signing_seed_sha256: "c".repeat(64),
            cas_root: "/var/lib/cieplik206/fakturownia/cas".to_owned(),
            argv_sha256: String::new(),
            environment_sha256: String::new(),
            authorization_signers: vec![AuthorizationSigner {
                id: "operator-1".to_owned(),
                public_key: STANDARD.encode(authorization_key.verifying_key().as_bytes()),
            }],
            consumption_authority_id: "authority-1".to_owned(),
            consumption_authority_public_key: STANDARD
                .encode(consumption_authority_key.verifying_key().as_bytes()),
            consumption_authority_policy_sha256: "d".repeat(64),
            consumption_store_id: "store-1".to_owned(),
            consumption_store_identity_sha256: "e".repeat(64),
            maximum_consumption_receipt_ttl_seconds: 360,
        };
        envelope.argv_sha256 = sha256_hex(&canonical::encode(&serde_json::json!({
            "contract": "cieplik206.fakturownia.native-supervisor-argv",
            "version": "1",
            "value": envelope.expected_argv(),
        }))?);
        envelope.environment_sha256 = sha256_hex(&canonical::encode(&serde_json::json!({
            "contract": "cieplik206.fakturownia.native-supervisor-environment",
            "version": "1",
            "value": envelope.expected_environment(),
        }))?);
        let signature = sign_base64(policy_key, &canonical::encode(&envelope)?);

        Ok(SignedDocument {
            envelope,
            signature,
        })
    }

    #[test]
    fn verifies_the_compiled_deployment_identity_and_exact_process_contract()
    -> Result<(), Box<dyn std::error::Error>> {
        let policy_key = SigningKey::generate(&mut OsRng);
        let authorization_key = SigningKey::generate(&mut OsRng);
        let now = OffsetDateTime::now_utc();
        let document = policy(&policy_key, &authorization_key, now)?;

        NativeSupervisorPolicyEnvelope::verify(
            &document,
            "native-deployment-policy-1",
            &policy_key.verifying_key(),
            now,
            &"2".repeat(64),
        )?;
        assert_eq!(
            document.envelope.expected_argv(),
            [
                "/usr/bin/php8.4",
                "-n",
                "/opt/cieplik206/fakturownia/launcher.php",
                "--supervised",
            ]
        );

        Ok(())
    }

    #[test]
    fn rejects_policy_tampering_and_another_compiled_semantics()
    -> Result<(), Box<dyn std::error::Error>> {
        let policy_key = SigningKey::generate(&mut OsRng);
        let authorization_key = SigningKey::generate(&mut OsRng);
        let now = OffsetDateTime::now_utc();
        let mut tampered = policy(&policy_key, &authorization_key, now)?;
        tampered.envelope.probe_uid = 992;
        let another_semantics = policy(&policy_key, &authorization_key, now)?;

        assert!(
            NativeSupervisorPolicyEnvelope::verify(
                &tampered,
                "native-deployment-policy-1",
                &policy_key.verifying_key(),
                now,
                &"2".repeat(64),
            )
            .is_err()
        );
        assert!(
            NativeSupervisorPolicyEnvelope::verify(
                &another_semantics,
                "native-deployment-policy-1",
                &policy_key.verifying_key(),
                now,
                &"f".repeat(64),
            )
            .is_err()
        );

        Ok(())
    }
}
