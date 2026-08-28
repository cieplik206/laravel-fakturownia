use std::collections::{BTreeMap, BTreeSet};

use serde::{Deserialize, Serialize};
use serde_json::{Map, Value};
use time::{Duration, OffsetDateTime};

use crate::broker::RunAuthorizationContext;
use crate::canonical;
use crate::crypto::{sha256_hex, verify_base64, verifying_key};
use crate::plan::NativeProbePlan;
use crate::trust::{
    AuthorizationSigner, SignedDocument, strict_utc_microsecond, validate_identifier,
    validate_sha256,
};
use crate::{BrokerError, BrokerResult};

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct AuthorizationHarness {
    pub repository_commit: String,
    pub code_sha256: String,
    pub launch_manifest_sha256: String,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct AuthorizationTarget {
    pub environment: String,
    pub profile: String,
    pub tenant_hmac_sha256: String,
    pub account_hmac_sha256: String,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct AuthorizationCommitments {
    pub scheme: String,
    pub configuration_hmac_sha256: String,
    pub policy_hmac_sha256: String,
    pub safety_hmac_sha256: String,
    pub templates_hmac_sha256: String,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct AuthorizationConsumption {
    pub authority_id: String,
    pub authority_policy_sha256: String,
    pub store_id: String,
    pub store_identity_sha256: String,
    pub run_id: String,
    pub replay_policy: String,
}

#[derive(Clone, Debug)]
pub struct ConsumptionAuthorityTrust {
    pub authority_id: String,
    pub public_key: String,
    pub policy_sha256: String,
    pub store_id: String,
    pub store_identity_sha256: String,
    pub maximum_receipt_ttl_seconds: u32,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct ConsumptionClaimRequest {
    pub contract: String,
    pub version: String,
    pub authority_id: String,
    pub authority_policy_sha256: String,
    pub store_id: String,
    pub store_identity_sha256: String,
    pub run_id: String,
    pub run_started_at: String,
    pub claim_nonce: String,
    pub harness: AuthorizationHarness,
    pub authorization_set_sha256: String,
    pub challenge_set_sha256: String,
    pub configuration_set_sha256: String,
    pub replay_policy: String,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct ConsumptionClaimCursor {
    pub store_id: String,
    pub sequence: String,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct ConsumptionReceiptEnvelope {
    pub contract: String,
    pub version: String,
    pub algorithm: String,
    pub signer_id: String,
    pub issued_at: String,
    pub expires_at: String,
    pub claim_cursor: ConsumptionClaimCursor,
    pub disposition: String,
    pub claim_request: ConsumptionClaimRequest,
    pub claim_request_sha256: String,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct SignedLiveProbeAuthorizationEnvelope {
    pub contract: String,
    pub version: String,
    pub algorithm: String,
    pub signer_id: String,
    pub issued_at: String,
    pub expires_at: String,
    pub evidence_contract: String,
    pub challenge: String,
    pub harness: AuthorizationHarness,
    pub target: AuthorizationTarget,
    pub commitments: AuthorizationCommitments,
    pub consumption: AuthorizationConsumption,
    pub limits: Map<String, Value>,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct AuthorizationBundle {
    pub contract: String,
    pub version: String,
    pub run_id: String,
    pub run_started_at: String,
    pub claim_nonce: String,
    pub evidence_contract: String,
    pub launch_manifest_sha256: String,
    pub authorization_set_sha256: String,
    pub challenge_set_sha256: String,
    pub configuration_set_sha256: String,
    pub claim_request_sha256: String,
    pub consumption_receipt_sha256: String,
    pub probe_plan: NativeProbePlan,
    pub authorizations: Vec<SignedDocument<SignedLiveProbeAuthorizationEnvelope>>,
    pub consumption_receipt: SignedDocument<ConsumptionReceiptEnvelope>,
}

impl AuthorizationBundle {
    pub const CONTRACT: &'static str = "cieplik206.fakturownia.native-authorization-bundle";

    pub fn verify(
        &self,
        signers: &[AuthorizationSigner],
        maximum_ttl_seconds: u32,
        launch_manifest_sha256: &str,
        authority: &ConsumptionAuthorityTrust,
        observed_at: OffsetDateTime,
    ) -> BrokerResult<RunAuthorizationContext> {
        if self.contract != Self::CONTRACT
            || self.version != "1"
            || self.authorizations.is_empty()
            || self.authorizations.len() > 16
            || self.run_id.len() != 32
            || !self.run_id.bytes().all(is_lower_hex_byte)
        {
            return Err(BrokerError::denied(
                "native authorization bundle contract is invalid",
            ));
        }

        crate::crypto::decode_canonical_base64(&self.claim_nonce, 32)?;
        let run_started_at = strict_utc_microsecond(&self.run_started_at)?;

        if run_started_at > observed_at {
            return Err(BrokerError::denied(
                "native authorization run start is in the future",
            ));
        }

        for digest in [
            &self.launch_manifest_sha256,
            &self.authorization_set_sha256,
            &self.challenge_set_sha256,
            &self.configuration_set_sha256,
            &self.claim_request_sha256,
            &self.consumption_receipt_sha256,
        ] {
            validate_sha256(digest)?;
        }

        if self.launch_manifest_sha256 != launch_manifest_sha256 {
            return Err(BrokerError::denied(
                "native authorization bundle binds another launch manifest",
            ));
        }

        let signer_map = signers
            .iter()
            .map(|signer| Ok((signer.id.as_str(), verifying_key(&signer.public_key)?)))
            .collect::<BrokerResult<BTreeMap<_, _>>>()?;
        let mut rows = Vec::new();
        let mut challenge_rows = Vec::new();
        let mut configuration_rows = Vec::new();
        let mut profiles = BTreeSet::new();
        let mut document_hashes = BTreeSet::new();
        let mut challenge_hashes = BTreeSet::new();

        for document in &self.authorizations {
            let authorization = &document.envelope;
            authorization.validate(
                maximum_ttl_seconds,
                &self.run_id,
                &self.evidence_contract,
                launch_manifest_sha256,
                run_started_at,
                observed_at,
            )?;
            assert_authority_binding(authorization, authority)?;
            let signer_key = signer_map
                .get(authorization.signer_id.as_str())
                .ok_or_else(|| BrokerError::denied("native authorization signer is not trusted"))?;
            verify_base64(
                signer_key,
                &document.canonical_envelope()?,
                &document.signature,
            )?;
            let authorization_sha256 = document.sha256()?;
            let challenge_sha256 = sha256_hex(authorization.challenge.as_bytes());

            if !profiles.insert(authorization.target.profile.clone())
                || !document_hashes.insert(authorization_sha256.clone())
                || !challenge_hashes.insert(challenge_sha256)
            {
                return Err(BrokerError::denied(
                    "native authorization bundle contains duplicate authority",
                ));
            }

            rows.push(serde_json::json!({
                "profile": authorization.target.profile,
                "sha256": authorization_sha256,
            }));
            challenge_rows.push(serde_json::json!({
                "profile": authorization.target.profile,
                "sha256": sha256_hex(authorization.challenge.as_bytes()),
            }));
            configuration_rows.push(serde_json::json!({
                "profile": authorization.target.profile,
                "sha256": authorization.commitments.configuration_hmac_sha256,
            }));
        }

        sort_rows(&mut rows);
        sort_rows(&mut challenge_rows);
        sort_rows(&mut configuration_rows);
        let actual_set_sha256 = set_sha256(rows)?;
        let actual_challenge_set_sha256 = set_sha256(challenge_rows)?;
        let actual_configuration_set_sha256 = set_sha256(configuration_rows)?;

        if actual_set_sha256 != self.authorization_set_sha256
            || actual_challenge_set_sha256 != self.challenge_set_sha256
            || actual_configuration_set_sha256 != self.configuration_set_sha256
        {
            return Err(BrokerError::denied(
                "native authorization aggregate digest does not match",
            ));
        }

        let first = &self.authorizations[0].envelope;
        let claim_request = ConsumptionClaimRequest {
            contract: "cieplik206.fakturownia.authorization-consumption-claim-request".to_owned(),
            version: "1".to_owned(),
            authority_id: authority.authority_id.clone(),
            authority_policy_sha256: authority.policy_sha256.clone(),
            store_id: authority.store_id.clone(),
            store_identity_sha256: authority.store_identity_sha256.clone(),
            run_id: self.run_id.clone(),
            run_started_at: self.run_started_at.clone(),
            claim_nonce: self.claim_nonce.clone(),
            harness: first.harness.clone(),
            authorization_set_sha256: self.authorization_set_sha256.clone(),
            challenge_set_sha256: self.challenge_set_sha256.clone(),
            configuration_set_sha256: self.configuration_set_sha256.clone(),
            replay_policy: "consume_after_read_preflight_before_mutating_http_no_retry".to_owned(),
        };
        let actual_claim_request_sha256 = sha256_hex(&canonical::encode(&claim_request)?);

        if actual_claim_request_sha256 != self.claim_request_sha256 {
            return Err(BrokerError::denied(
                "native authorization claim request digest does not match",
            ));
        }

        verify_consumption_receipt(
            &self.consumption_receipt,
            &claim_request,
            authority,
            run_started_at,
            observed_at,
        )?;

        if self.consumption_receipt.sha256()? != self.consumption_receipt_sha256 {
            return Err(BrokerError::denied(
                "native consumption receipt digest does not match",
            ));
        }

        Ok(RunAuthorizationContext {
            run_id: self.run_id.clone(),
            run_started_at: self.run_started_at.clone(),
            evidence_contract: self.evidence_contract.clone(),
            profiles: profiles.into_iter().collect(),
            claim_nonce: self.claim_nonce.clone(),
            authorization_set_sha256: self.authorization_set_sha256.clone(),
            claim_request_sha256: self.claim_request_sha256.clone(),
            consumption_receipt_sha256: self.consumption_receipt_sha256.clone(),
            authorization_bundle_sha256: String::new(),
            probe_plan_sha256: self.probe_plan.sha256()?,
        })
    }
}

impl SignedLiveProbeAuthorizationEnvelope {
    #[allow(clippy::too_many_arguments)]
    fn validate(
        &self,
        maximum_ttl_seconds: u32,
        run_id: &str,
        evidence_contract: &str,
        launch_manifest_sha256: &str,
        run_started_at: OffsetDateTime,
        observed_at: OffsetDateTime,
    ) -> BrokerResult<()> {
        if self.contract != "cieplik206.fakturownia.live-probe-authorization"
            || self.version != "1"
            || self.algorithm != "Ed25519"
            || self.evidence_contract != evidence_contract
            || self.consumption.run_id != run_id
            || self.harness.launch_manifest_sha256 != launch_manifest_sha256
            || self.commitments.scheme != "hmac-sha256-ephemeral-run-key-v1"
            || self.consumption.replay_policy
                != "consume_after_read_preflight_before_mutating_http_no_retry"
            || self.limits.is_empty()
        {
            return Err(BrokerError::denied(
                "native signed authorization contract is invalid",
            ));
        }

        validate_identifier(&self.signer_id)?;
        validate_identifier(&self.consumption.authority_id)?;
        validate_identifier(&self.consumption.store_id)?;
        crate::crypto::decode_canonical_base64(&self.challenge, 32)?;
        let issued_at = strict_utc_microsecond(&self.issued_at)?;
        let expires_at = strict_utc_microsecond(&self.expires_at)?;

        if issued_at > run_started_at
            || expires_at <= observed_at
            || expires_at - issued_at > Duration::seconds(i64::from(maximum_ttl_seconds))
        {
            return Err(BrokerError::denied(
                "native signed authorization is outside its validity window",
            ));
        }

        if !matches!(
            self.evidence_contract.as_str(),
            "fakturownia-invoice-identity-s0.3-v1" | "fakturownia-ksef-demo-s0.4-v1"
        ) || self.target.profile.is_empty()
            || self.target.profile.len() > 64
            || self.target.profile.bytes().any(|byte| {
                !(byte.is_ascii_lowercase() || byte.is_ascii_digit() || matches!(byte, b'_' | b'-'))
            })
        {
            return Err(BrokerError::denied(
                "native signed authorization target is invalid",
            ));
        }

        for digest in [
            &self.harness.code_sha256,
            &self.harness.launch_manifest_sha256,
            &self.target.tenant_hmac_sha256,
            &self.target.account_hmac_sha256,
            &self.commitments.configuration_hmac_sha256,
            &self.commitments.policy_hmac_sha256,
            &self.commitments.safety_hmac_sha256,
            &self.commitments.templates_hmac_sha256,
            &self.consumption.authority_policy_sha256,
            &self.consumption.store_identity_sha256,
        ] {
            validate_sha256(digest)?;
        }

        if !matches!(self.harness.repository_commit.len(), 40 | 64)
            || !self
                .harness
                .repository_commit
                .bytes()
                .all(is_lower_hex_byte)
        {
            return Err(BrokerError::denied(
                "native signed authorization repository commit is invalid",
            ));
        }

        Ok(())
    }
}

fn assert_authority_binding(
    authorization: &SignedLiveProbeAuthorizationEnvelope,
    authority: &ConsumptionAuthorityTrust,
) -> BrokerResult<()> {
    validate_identifier(&authority.authority_id)?;
    validate_identifier(&authority.store_id)?;
    validate_sha256(&authority.policy_sha256)?;
    validate_sha256(&authority.store_identity_sha256)?;
    verifying_key(&authority.public_key)?;

    if authority.maximum_receipt_ttl_seconds == 0
        || authority.maximum_receipt_ttl_seconds > 86_400
        || authorization.consumption.authority_id != authority.authority_id
        || authorization.consumption.authority_policy_sha256 != authority.policy_sha256
        || authorization.consumption.store_id != authority.store_id
        || authorization.consumption.store_identity_sha256 != authority.store_identity_sha256
    {
        return Err(BrokerError::denied(
            "native authorization does not bind the trusted consumption authority",
        ));
    }

    Ok(())
}

fn verify_consumption_receipt(
    receipt: &SignedDocument<ConsumptionReceiptEnvelope>,
    expected_claim_request: &ConsumptionClaimRequest,
    authority: &ConsumptionAuthorityTrust,
    run_started_at: OffsetDateTime,
    observed_at: OffsetDateTime,
) -> BrokerResult<()> {
    let envelope = &receipt.envelope;
    let actual_claim_request_sha256 = sha256_hex(&canonical::encode(&envelope.claim_request)?);

    if envelope.contract != "cieplik206.fakturownia.authorization-consumption-receipt"
        || envelope.version != "1"
        || envelope.algorithm != "Ed25519"
        || envelope.signer_id != authority.authority_id
        || envelope.claim_cursor.store_id != authority.store_id
        || !is_positive_decimal(&envelope.claim_cursor.sequence)
        || envelope.disposition != "fresh_direct_grant"
        || envelope.claim_request_sha256 != actual_claim_request_sha256
        || canonical::encode(&envelope.claim_request)? != canonical::encode(expected_claim_request)?
    {
        return Err(BrokerError::denied(
            "native consumption receipt contract is invalid",
        ));
    }

    verify_base64(
        &verifying_key(&authority.public_key)?,
        &receipt.canonical_envelope()?,
        &receipt.signature,
    )?;
    let issued_at = strict_utc_microsecond(&envelope.issued_at)?;
    let expires_at = strict_utc_microsecond(&envelope.expires_at)?;

    if issued_at < run_started_at
        || issued_at > observed_at
        || expires_at <= observed_at
        || expires_at - issued_at
            > Duration::seconds(i64::from(authority.maximum_receipt_ttl_seconds))
    {
        return Err(BrokerError::denied(
            "native consumption receipt is outside its validity window",
        ));
    }

    Ok(())
}

fn sort_rows(rows: &mut [Value]) {
    rows.sort_by(|left, right| {
        left.get("profile")
            .and_then(Value::as_str)
            .cmp(&right.get("profile").and_then(Value::as_str))
    });
}

fn set_sha256(rows: Vec<Value>) -> BrokerResult<String> {
    Ok(sha256_hex(&canonical::encode(&serde_json::json!({
        "contract": "cieplik206.fakturownia.authorization-consumption-set",
        "version": "1",
        "value": rows,
    }))?))
}

fn is_positive_decimal(value: &str) -> bool {
    !value.is_empty()
        && !value.starts_with('0')
        && value.len() <= 39
        && value.bytes().all(|byte| byte.is_ascii_digit())
}

const fn is_lower_hex_byte(byte: u8) -> bool {
    byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase()
}

#[cfg(test)]
mod tests {
    use base64::Engine;
    use base64::engine::general_purpose::STANDARD;
    use ed25519_dalek::SigningKey;
    use rand::rngs::OsRng;
    use serde_json::{Map, Value};
    use time::{Duration, OffsetDateTime};

    use crate::canonical;
    use crate::crypto::{sha256_hex, sign_base64};
    use crate::trust::{AuthorizationSigner, SignedDocument, format_utc_microsecond};

    use super::{
        AuthorizationBundle, AuthorizationCommitments, AuthorizationConsumption,
        AuthorizationHarness, AuthorizationTarget, ConsumptionAuthorityTrust,
        ConsumptionClaimCursor, ConsumptionClaimRequest, ConsumptionReceiptEnvelope,
        SignedLiveProbeAuthorizationEnvelope, set_sha256,
    };
    use crate::plan::{
        InvoiceIdentityLimits, InvoiceIdentityProbePlan, InvoiceIdentityTarget, NativeProbePlan,
    };

    struct Fixture {
        bundle: AuthorizationBundle,
        signer: AuthorizationSigner,
        authority_signing_key: SigningKey,
        observed_at: OffsetDateTime,
        launch_manifest_sha256: String,
        authority: ConsumptionAuthorityTrust,
    }

    fn fixture() -> Result<Fixture, Box<dyn std::error::Error>> {
        let signing_key = SigningKey::generate(&mut OsRng);
        let authority_key = SigningKey::generate(&mut OsRng);
        let observed_at = OffsetDateTime::now_utc();
        let run_started_at = format_utc_microsecond(observed_at)?;
        let launch_manifest_sha256 = "1".repeat(64);
        let envelope = SignedLiveProbeAuthorizationEnvelope {
            contract: "cieplik206.fakturownia.live-probe-authorization".to_owned(),
            version: "1".to_owned(),
            algorithm: "Ed25519".to_owned(),
            signer_id: "operator-1".to_owned(),
            issued_at: format_utc_microsecond(observed_at - Duration::minutes(1))?,
            expires_at: format_utc_microsecond(observed_at + Duration::minutes(5))?,
            evidence_contract: "fakturownia-invoice-identity-s0.3-v1".to_owned(),
            challenge: STANDARD.encode([2_u8; 32]),
            harness: AuthorizationHarness {
                repository_commit: "3".repeat(40),
                code_sha256: "4".repeat(64),
                launch_manifest_sha256: launch_manifest_sha256.clone(),
            },
            target: AuthorizationTarget {
                environment: "demo_pl".to_owned(),
                profile: "invoice_identity".to_owned(),
                tenant_hmac_sha256: "5".repeat(64),
                account_hmac_sha256: "6".repeat(64),
            },
            commitments: AuthorizationCommitments {
                scheme: "hmac-sha256-ephemeral-run-key-v1".to_owned(),
                configuration_hmac_sha256: "7".repeat(64),
                policy_hmac_sha256: "8".repeat(64),
                safety_hmac_sha256: "9".repeat(64),
                templates_hmac_sha256: "a".repeat(64),
            },
            consumption: AuthorizationConsumption {
                authority_id: "authority-1".to_owned(),
                authority_policy_sha256: "b".repeat(64),
                store_id: "store-1".to_owned(),
                store_identity_sha256: "c".repeat(64),
                run_id: "d".repeat(32),
                replay_policy: "consume_after_read_preflight_before_mutating_http_no_retry"
                    .to_owned(),
            },
            limits: Map::from_iter([("maximum_effects".to_owned(), Value::from(1))]),
        };
        let authorization = SignedDocument {
            signature: sign_base64(&signing_key, &canonical::encode(&envelope)?),
            envelope,
        };
        let authorization_set_sha256 = sha256_hex(&canonical::encode(&serde_json::json!({
            "contract": "cieplik206.fakturownia.authorization-consumption-set",
            "version": "1",
            "value": [{
                "profile": "invoice_identity",
                "sha256": authorization.sha256()?,
            }],
        }))?);
        let challenge_set_sha256 = set_sha256(vec![serde_json::json!({
            "profile": "invoice_identity",
            "sha256": sha256_hex(authorization.envelope.challenge.as_bytes()),
        })])?;
        let configuration_set_sha256 = set_sha256(vec![serde_json::json!({
            "profile": "invoice_identity",
            "sha256": authorization.envelope.commitments.configuration_hmac_sha256.clone(),
        })])?;
        let claim_request = ConsumptionClaimRequest {
            contract: "cieplik206.fakturownia.authorization-consumption-claim-request".to_owned(),
            version: "1".to_owned(),
            authority_id: "authority-1".to_owned(),
            authority_policy_sha256: "b".repeat(64),
            store_id: "store-1".to_owned(),
            store_identity_sha256: "c".repeat(64),
            run_id: "d".repeat(32),
            run_started_at: run_started_at.clone(),
            claim_nonce: STANDARD.encode([10_u8; 32]),
            harness: authorization.envelope.harness.clone(),
            authorization_set_sha256: authorization_set_sha256.clone(),
            challenge_set_sha256: challenge_set_sha256.clone(),
            configuration_set_sha256: configuration_set_sha256.clone(),
            replay_policy: "consume_after_read_preflight_before_mutating_http_no_retry".to_owned(),
        };
        let claim_request_sha256 = sha256_hex(&canonical::encode(&claim_request)?);
        let receipt_envelope = ConsumptionReceiptEnvelope {
            contract: "cieplik206.fakturownia.authorization-consumption-receipt".to_owned(),
            version: "1".to_owned(),
            algorithm: "Ed25519".to_owned(),
            signer_id: "authority-1".to_owned(),
            issued_at: run_started_at.clone(),
            expires_at: format_utc_microsecond(observed_at + Duration::minutes(5))?,
            claim_cursor: ConsumptionClaimCursor {
                store_id: "store-1".to_owned(),
                sequence: "1".to_owned(),
            },
            disposition: "fresh_direct_grant".to_owned(),
            claim_request,
            claim_request_sha256: claim_request_sha256.clone(),
        };
        let consumption_receipt = SignedDocument {
            signature: sign_base64(&authority_key, &canonical::encode(&receipt_envelope)?),
            envelope: receipt_envelope,
        };
        let consumption_receipt_sha256 = consumption_receipt.sha256()?;
        let authority_public_key = STANDARD.encode(authority_key.verifying_key().as_bytes());
        let bundle = AuthorizationBundle {
            contract: AuthorizationBundle::CONTRACT.to_owned(),
            version: "1".to_owned(),
            run_id: "d".repeat(32),
            run_started_at,
            claim_nonce: STANDARD.encode([10_u8; 32]),
            evidence_contract: "fakturownia-invoice-identity-s0.3-v1".to_owned(),
            launch_manifest_sha256: launch_manifest_sha256.clone(),
            authorization_set_sha256,
            challenge_set_sha256,
            configuration_set_sha256,
            claim_request_sha256,
            consumption_receipt_sha256,
            probe_plan: NativeProbePlan::InvoiceIdentity(InvoiceIdentityProbePlan {
                contract: NativeProbePlan::CONTRACT.to_owned(),
                version: "1".to_owned(),
                environment: "demo_pl".to_owned(),
                limits: InvoiceIdentityLimits {
                    visibility_window_ms: 10_000,
                    poll_interval_ms: 250,
                    max_search_pages: 10,
                    lost_response_timeout_ms: 2_000,
                    connect_timeout_ms: 5_000,
                    request_timeout_ms: 30_000,
                    write_attempt_budget: 11,
                },
                targets: vec![
                    InvoiceIdentityTarget {
                        target_key: "primary".to_owned(),
                        expected_account_fingerprint: "5".repeat(64),
                    },
                    InvoiceIdentityTarget {
                        target_key: "secondary".to_owned(),
                        expected_account_fingerprint: "6".repeat(64),
                    },
                ],
                payload: Map::from_iter([
                    (
                        "correction_invoice".to_owned(),
                        serde_json::json!({"kind": "correction"}),
                    ),
                    ("invoice".to_owned(), serde_json::json!({"kind": "vat"})),
                    (
                        "safety".to_owned(),
                        serde_json::json!({
                            "throwaway_tenants": true,
                            "ksef_auto_send_disabled": true,
                            "email_delivery_disabled": true,
                        }),
                    ),
                    (
                        "secondary_account_invoice".to_owned(),
                        serde_json::json!({"kind": "vat"}),
                    ),
                    (
                        "secondary_department_id".to_owned(),
                        Value::String("2".to_owned()),
                    ),
                ]),
            }),
            authorizations: vec![authorization],
            consumption_receipt,
        };

        Ok(Fixture {
            bundle,
            signer: AuthorizationSigner {
                id: "operator-1".to_owned(),
                public_key: STANDARD.encode(signing_key.verifying_key().as_bytes()),
            },
            authority_signing_key: authority_key,
            observed_at,
            launch_manifest_sha256,
            authority: ConsumptionAuthorityTrust {
                authority_id: "authority-1".to_owned(),
                public_key: authority_public_key,
                policy_sha256: "b".repeat(64),
                store_id: "store-1".to_owned(),
                store_identity_sha256: "c".repeat(64),
                maximum_receipt_ttl_seconds: 360,
            },
        })
    }

    #[test]
    fn verifies_every_authorization_before_releasing_run_authority()
    -> Result<(), Box<dyn std::error::Error>> {
        let fixture = fixture()?;
        let context = fixture.bundle.verify(
            &[fixture.signer],
            360,
            &fixture.launch_manifest_sha256,
            &fixture.authority,
            fixture.observed_at,
        )?;

        assert_eq!(context.run_id, "d".repeat(32));
        assert_eq!(context.profiles, ["invoice_identity"]);
        assert_eq!(context.claim_nonce, STANDARD.encode([10_u8; 32]));
        assert_ne!(context.claim_nonce, STANDARD.encode([11_u8; 32]));

        Ok(())
    }

    #[test]
    fn rejects_tampering_unknown_signers_and_cross_manifest_replay()
    -> Result<(), Box<dyn std::error::Error>> {
        let mut tampered = fixture()?;
        tampered.bundle.authorizations[0]
            .envelope
            .limits
            .insert("maximum_effects".to_owned(), Value::from(2));
        let unknown = fixture()?;
        let cross_manifest = fixture()?;

        assert!(
            tampered
                .bundle
                .verify(
                    &[tampered.signer],
                    360,
                    &tampered.launch_manifest_sha256,
                    &tampered.authority,
                    tampered.observed_at,
                )
                .is_err()
        );
        assert!(
            unknown
                .bundle
                .verify(
                    &[AuthorizationSigner {
                        id: "another-operator".to_owned(),
                        public_key: unknown.signer.public_key,
                    }],
                    360,
                    &unknown.launch_manifest_sha256,
                    &unknown.authority,
                    unknown.observed_at,
                )
                .is_err()
        );
        assert!(
            cross_manifest
                .bundle
                .verify(
                    &[cross_manifest.signer],
                    360,
                    &"0".repeat(64),
                    &cross_manifest.authority,
                    cross_manifest.observed_at,
                )
                .is_err()
        );

        Ok(())
    }

    #[test]
    fn rejects_tampered_or_recovered_consumption_receipts() -> Result<(), Box<dyn std::error::Error>>
    {
        let mut tampered = fixture()?;
        tampered
            .bundle
            .consumption_receipt
            .envelope
            .claim_request
            .run_id = "e".repeat(32);

        assert!(
            tampered
                .bundle
                .verify(
                    &[tampered.signer],
                    360,
                    &tampered.launch_manifest_sha256,
                    &tampered.authority,
                    tampered.observed_at,
                )
                .is_err()
        );

        let mut recovered = fixture()?;
        recovered.bundle.consumption_receipt.envelope.disposition =
            "recovered_existing_claim".to_owned();
        recovered.bundle.consumption_receipt.signature = sign_base64(
            &recovered.authority_signing_key,
            &canonical::encode(&recovered.bundle.consumption_receipt.envelope)?,
        );
        recovered.bundle.consumption_receipt_sha256 =
            recovered.bundle.consumption_receipt.sha256()?;

        assert!(
            recovered
                .bundle
                .verify(
                    &[recovered.signer],
                    360,
                    &recovered.launch_manifest_sha256,
                    &recovered.authority,
                    recovered.observed_at,
                )
                .is_err()
        );

        Ok(())
    }
}
