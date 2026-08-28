#[cfg(all(target_os = "linux", feature = "network"))]
use std::collections::{BTreeMap, BTreeSet};
#[cfg(all(target_os = "linux", feature = "network"))]
use std::io::{BufRead, BufReader};
#[cfg(all(target_os = "linux", feature = "network"))]
use std::os::unix::process::CommandExt;
#[cfg(all(target_os = "linux", feature = "network"))]
use std::path::Path;
#[cfg(all(target_os = "linux", feature = "network"))]
use std::process::{Child, Command, Stdio};

#[cfg(all(target_os = "linux", feature = "network"))]
use base64::Engine;
#[cfg(all(target_os = "linux", feature = "network"))]
use base64::engine::general_purpose::STANDARD;
#[cfg(all(target_os = "linux", feature = "network"))]
use rand::RngCore;
#[cfg(all(target_os = "linux", feature = "network"))]
use rand::rngs::OsRng;
#[cfg(all(target_os = "linux", feature = "network"))]
use serde::{Deserialize, Serialize};
#[cfg(all(target_os = "linux", feature = "network"))]
use time::OffsetDateTime;
#[cfg(all(target_os = "linux", feature = "network"))]
use zeroize::Zeroizing;

#[cfg(all(target_os = "linux", feature = "network"))]
use crate::authorization::AuthorizationBundle;
#[cfg(all(target_os = "linux", feature = "network"))]
use crate::broker::RunAuthorizationContext;
#[cfg(all(target_os = "linux", feature = "network"))]
use crate::broker::{
    Broker, ProviderCredentialSet, ProviderTransport, UreqProviderTransport,
    verify_account_observation,
};
#[cfg(all(target_os = "linux", feature = "network"))]
use crate::canonical;
#[cfg(all(target_os = "linux", feature = "network"))]
use crate::crypto::signing_key;
#[cfg(all(target_os = "linux", feature = "network"))]
use crate::crypto::{constant_time_hex_equal, sha256_hex, verifying_key};
#[cfg(all(target_os = "linux", feature = "network"))]
use crate::frame;
#[cfg(all(target_os = "linux", feature = "network"))]
use crate::plan::NativeProbePlan;
#[cfg(all(target_os = "linux", feature = "network"))]
use crate::policy::{NativeSupervisorPolicyEnvelope, read_trusted_file};
#[cfg(all(target_os = "linux", feature = "network"))]
use crate::protocol::{
    ConcurrentEffectExecutionProposal, EffectExecutionProposal, ReadObservationProposal,
    WIRE_VERSION,
};
#[cfg(all(target_os = "linux", feature = "network"))]
use crate::trust::{
    NativeBrokerTrustPolicyEnvelope, NativeSupervisorAttestationEnvelope, SignedDocument,
};
use crate::{BrokerError, BrokerResult};

#[cfg(all(target_os = "linux", feature = "network"))]
const MAXIMUM_POLICY_BYTES: usize = 1_048_576;
#[cfg(all(target_os = "linux", feature = "network"))]
const MAXIMUM_AUTHORIZATION_BYTES: usize = 1_048_576;
#[cfg(all(target_os = "linux", feature = "network"))]
const MAXIMUM_SECRET_BYTES: usize = 1_048_576;
#[cfg(all(target_os = "linux", feature = "network"))]
const MAXIMUM_WIRE_DOCUMENTS_PER_RUN: usize = 50_000;
#[cfg(all(target_os = "linux", feature = "network"))]
const YAMA_PTRACE_SCOPE_PATH: &str = "/proc/sys/kernel/yama/ptrace_scope";

#[cfg(all(target_os = "linux", feature = "network"))]
#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct LaunchHandshake {
    contract: String,
    version: String,
    launch_manifest_sha256: String,
    supervisor_semantics_sha256: String,
}

#[cfg(all(target_os = "linux", feature = "network"))]
#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct ReadyHandshake {
    contract: String,
    version: String,
    launch_manifest_sha256: String,
    supervisor_semantics_sha256: String,
}

#[cfg(all(target_os = "linux", feature = "network"))]
#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct AuthorityHandoff {
    contract: String,
    version: String,
    trust_policy: SignedDocument<NativeBrokerTrustPolicyEnvelope>,
    supervisor_attestation: SignedDocument<NativeSupervisorAttestationEnvelope>,
    authorization: RunAuthorizationContext,
    authorization_bundle: AuthorizationBundle,
}

#[cfg(all(target_os = "linux", feature = "network"))]
struct LoadedAuthorization {
    bundle: AuthorizationBundle,
    context: RunAuthorizationContext,
}

pub struct CompiledTrust<'a> {
    pub policy_path: &'a str,
    pub expected_policy_signer_id: &'a str,
    pub policy_public_key_base64: &'a str,
    pub supervisor_semantics_sha256: &'a str,
}

#[cfg(all(target_os = "linux", feature = "network"))]
pub fn run(compiled: &CompiledTrust<'_>) -> BrokerResult<()> {
    run_linux(compiled)
}

#[cfg(not(all(target_os = "linux", feature = "network")))]
pub const fn run(_compiled: &CompiledTrust<'_>) -> BrokerResult<()> {
    Err(BrokerError::denied(
        "native supervisor requires Linux and the pinned network transport",
    ))
}

#[cfg(all(target_os = "linux", feature = "network"))]
fn run_linux(compiled: &CompiledTrust<'_>) -> BrokerResult<()> {
    if !rustix::process::geteuid().is_root() {
        return Err(BrokerError::denied("native supervisor must run as root"));
    }

    let policy_bytes =
        read_trusted_file(Path::new(compiled.policy_path), MAXIMUM_POLICY_BYTES, false)?;
    canonical::decode_object(&policy_bytes)?;
    let policy_document: SignedDocument<NativeSupervisorPolicyEnvelope> =
        serde_json::from_slice(&policy_bytes)
            .map_err(|_| BrokerError::denied("native supervisor policy is invalid"))?;
    let policy_public_key = verifying_key(compiled.policy_public_key_base64)?;
    NativeSupervisorPolicyEnvelope::verify(
        &policy_document,
        compiled.expected_policy_signer_id,
        &policy_public_key,
        OffsetDateTime::now_utc(),
        compiled.supervisor_semantics_sha256,
    )?;
    let policy = &policy_document.envelope;
    policy.assert_prelaunch_assets()?;
    assert_ptrace_is_disabled()?;
    let broker_policy_sha256 = sha256_hex(&policy_bytes);
    let trust_policy = load_trust_policy(policy, &broker_policy_sha256)?;
    let authorization = load_authorization(policy)?;

    harden_process()?;
    let mut child = spawn_child(policy)?;
    let run_result = supervise_child(
        &mut child,
        policy,
        &trust_policy,
        authorization,
        &broker_policy_sha256,
    );

    if run_result.is_err() {
        let _ = child.kill();
    }

    run_result?;
    let status = child
        .wait()
        .map_err(|_| BrokerError::denied("cannot wait for supervised probe"))?;

    if !status.success() {
        return Err(BrokerError::denied("supervised probe failed"));
    }

    Ok(())
}

#[cfg(all(target_os = "linux", feature = "network"))]
fn harden_process() -> BrokerResult<()> {
    use rustix::process::{DumpableBehavior, set_dumpable_behavior};
    use rustix::process::{Resource, Rlimit};
    use rustix::thread::{set_no_new_privs, set_thread_groups};

    rustix::process::setrlimit(
        Resource::Core,
        Rlimit {
            current: Some(0),
            maximum: Some(0),
        },
    )
    .map_err(|_| BrokerError::denied("cannot disable native supervisor core dumps"))?;
    set_thread_groups(&[])
        .map_err(|_| BrokerError::denied("cannot clear native supervisor supplementary groups"))?;
    set_dumpable_behavior(DumpableBehavior::NotDumpable)
        .map_err(|_| BrokerError::denied("cannot disable native supervisor dumpability"))?;
    set_no_new_privs(true)
        .map_err(|_| BrokerError::denied("cannot enable native supervisor no-new-privileges"))
}

#[cfg(all(target_os = "linux", feature = "network"))]
fn assert_ptrace_is_disabled() -> BrokerResult<()> {
    let value = std::fs::read_to_string(YAMA_PTRACE_SCOPE_PATH)
        .map_err(|_| BrokerError::denied("kernel ptrace policy is unavailable"))?;

    if value != "3\n" && value != "3" {
        return Err(BrokerError::denied(
            "kernel ptrace policy must forbid every attach operation",
        ));
    }

    Ok(())
}

#[cfg(all(target_os = "linux", feature = "network"))]
fn spawn_child(policy: &NativeSupervisorPolicyEnvelope) -> BrokerResult<Child> {
    let environment = policy.expected_environment();
    let mut command = Command::new(&policy.php_executable_path);
    command
        .arg("-n")
        .arg(&policy.launcher_path)
        .arg("--supervised")
        .env_clear()
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::inherit())
        .uid(policy.probe_uid)
        .gid(policy.probe_gid);

    for (name, value) in environment {
        command.env(name, value);
    }

    command
        .spawn()
        .map_err(|_| BrokerError::denied("cannot start supervised probe"))
}

#[cfg(all(target_os = "linux", feature = "network"))]
fn supervise_child(
    child: &mut Child,
    policy: &NativeSupervisorPolicyEnvelope,
    trust_policy: &SignedDocument<NativeBrokerTrustPolicyEnvelope>,
    authorization: LoadedAuthorization,
    broker_policy_sha256: &str,
) -> BrokerResult<()> {
    let mut input = child
        .stdin
        .take()
        .ok_or_else(|| BrokerError::denied("supervised probe input is unavailable"))?;
    let output = child
        .stdout
        .take()
        .ok_or_else(|| BrokerError::denied("supervised probe output is unavailable"))?;
    let mut output = BufReader::new(output);
    let launch = LaunchHandshake {
        contract: "cieplik206.fakturownia.native-supervisor-launch".to_owned(),
        version: WIRE_VERSION.to_owned(),
        launch_manifest_sha256: policy.launch_manifest_sha256.clone(),
        supervisor_semantics_sha256: policy.supervisor_semantics_sha256.clone(),
    };
    frame::write(&mut input, &launch)?;
    let ready: ReadyHandshake = read_document(&mut output)?;

    if ready.contract != "cieplik206.fakturownia.native-supervisor-ready"
        || ready.version != WIRE_VERSION
        || !constant_time_hex_equal(
            &ready.launch_manifest_sha256,
            &policy.launch_manifest_sha256,
        )
        || !constant_time_hex_equal(
            &ready.supervisor_semantics_sha256,
            &policy.supervisor_semantics_sha256,
        )
    {
        return Err(BrokerError::denied(
            "supervised probe readiness binding is invalid",
        ));
    }

    let mut run_nonce = [0_u8; 32];
    OsRng.fill_bytes(&mut run_nonce);
    let credential_bytes = read_secret(
        &policy.provider_credential_path,
        &policy.provider_credential_sha256,
        MAXIMUM_SECRET_BYTES,
    )?;
    canonical::decode_object(&credential_bytes)?;
    let credentials: ProviderCredentialSet = serde_json::from_slice(&credential_bytes)
        .map_err(|_| BrokerError::denied("native broker credential set is invalid"))?;
    authorization
        .bundle
        .probe_plan
        .verify(&authorization.bundle, &credentials)?;
    let transport = UreqProviderTransport;
    verify_remote_accounts(&authorization.bundle.probe_plan, &credentials, &transport)?;
    let supervisor_seed = read_secret(
        &policy.supervisor_signing_seed_path,
        &policy.supervisor_signing_seed_sha256,
        32,
    )?;
    let result_seed = read_secret(
        &policy.effect_result_signing_seed_path,
        &policy.effect_result_signing_seed_sha256,
        32,
    )?;
    let supervisor_signing_key = signing_key(&supervisor_seed)?;
    let result_signing_key = signing_key(&result_seed)?;

    if supervisor_signing_key.verifying_key() != trust_policy.envelope.supervisor_verifying_key()?
        || result_signing_key.verifying_key() != trust_policy.envelope.result_verifying_key()?
    {
        return Err(BrokerError::denied(
            "native supervisor signing keys do not match trust policy",
        ));
    }

    let attestation = NativeSupervisorAttestationEnvelope::issue(
        &trust_policy.envelope,
        &supervisor_signing_key,
        policy.launch_manifest_sha256.clone(),
        STANDARD.encode(run_nonce),
        authorization.context.authorization_set_sha256.clone(),
        authorization.context.authorization_bundle_sha256.clone(),
        authorization.context.probe_plan_sha256.clone(),
        OffsetDateTime::now_utc(),
    )?;

    let handoff = AuthorityHandoff {
        contract: "cieplik206.fakturownia.native-supervisor-authority".to_owned(),
        version: WIRE_VERSION.to_owned(),
        trust_policy: trust_policy.clone(),
        supervisor_attestation: attestation.clone(),
        authorization: authorization.context.clone(),
        authorization_bundle: authorization.bundle.clone(),
    };
    frame::write(&mut input, &handoff)?;
    let broker = Broker {
        trust_policy: &trust_policy.envelope,
        attestation: &attestation,
        authorization: &authorization.context,
        credentials: &credentials,
        cas_root: Path::new(&policy.cas_root),
        result_signing_key: &result_signing_key,
        transport: &transport,
    };

    let mut effect_sequences = BTreeSet::new();
    let mut effect_counts = BTreeMap::<String, usize>::new();
    let mut observation_ids = BTreeSet::new();

    for _ in 0..MAXIMUM_WIRE_DOCUMENTS_PER_RUN {
        if output
            .fill_buf()
            .map_err(|_| BrokerError::denied("cannot inspect supervised probe output"))?
            .is_empty()
        {
            return verify_effect_budget_completion(
                &authorization.context.evidence_contract,
                &effect_counts,
            );
        }

        let object = frame::read(&mut output)?;
        let contract = object
            .get("contract")
            .and_then(serde_json::Value::as_str)
            .ok_or_else(|| BrokerError::denied("native supervisor wire contract is missing"))?;
        let document = serde_json::Value::Object(object);

        match contract {
            EffectExecutionProposal::CONTRACT => {
                let proposal: EffectExecutionProposal = serde_json::from_value(document)
                    .map_err(|_| BrokerError::denied("native effect proposal is invalid"))?;
                record_effect_proposal(&proposal, &mut effect_sequences, &mut effect_counts)?;
                let response = broker.execute_proposal(&proposal, OffsetDateTime::now_utc())?;
                frame::write(&mut input, &response)?;
            }
            ConcurrentEffectExecutionProposal::CONTRACT => {
                let batch: ConcurrentEffectExecutionProposal = serde_json::from_value(document)
                    .map_err(|_| {
                        BrokerError::denied("native concurrent effect batch is invalid")
                    })?;

                for proposal in &batch.proposals {
                    record_effect_proposal(proposal, &mut effect_sequences, &mut effect_counts)?;
                }

                let response =
                    broker.execute_concurrent_proposals(&batch, OffsetDateTime::now_utc())?;
                frame::write(&mut input, &response)?;
            }
            ReadObservationProposal::CONTRACT => {
                let proposal: ReadObservationProposal = serde_json::from_value(document)
                    .map_err(|_| BrokerError::denied("native read proposal is invalid"))?;

                if !observation_ids.insert(proposal.observation_id.clone()) {
                    return Err(BrokerError::denied(
                        "native read observation identity was reused",
                    ));
                }

                let response = broker.observe_proposal(&proposal, OffsetDateTime::now_utc())?;
                frame::write(&mut input, &response)?;
            }
            _ => {
                return Err(BrokerError::denied(
                    "native supervisor wire contract is denied",
                ));
            }
        }
    }

    Err(BrokerError::denied(
        "supervised probe exceeded its wire-document budget",
    ))
}

#[cfg(all(target_os = "linux", feature = "network"))]
fn record_effect_proposal(
    proposal: &EffectExecutionProposal,
    sequences: &mut BTreeSet<(String, u32)>,
    counts: &mut BTreeMap<String, usize>,
) -> BrokerResult<()> {
    if !sequences.insert((proposal.capability.clone(), proposal.effect_sequence)) {
        return Err(BrokerError::denied(
            "native broker effect sequence was reused",
        ));
    }

    let count = counts.entry(proposal.capability.clone()).or_default();
    *count += 1;
    let maximum = match proposal.capability.as_str() {
        "invoice.vat.issue" => 11,
        "contract_probe.invoice.fixture.issue" => 8,
        "invoice.ksef.ensure_accepted" => 2,
        _ => {
            return Err(BrokerError::denied(
                "native broker effect capability is denied",
            ));
        }
    };

    if *count > maximum {
        return Err(BrokerError::denied(
            "native broker effect capability exceeded its exact budget",
        ));
    }

    Ok(())
}

#[cfg(all(target_os = "linux", feature = "network"))]
fn verify_effect_budget_completion(
    evidence_contract: &str,
    counts: &BTreeMap<String, usize>,
) -> BrokerResult<()> {
    let complete = match evidence_contract {
        "fakturownia-invoice-identity-s0.3-v1" => {
            counts.len() == 1 && counts.get("invoice.vat.issue") == Some(&11)
        }
        "fakturownia-ksef-demo-s0.4-v1" => {
            counts.len() == 2
                && counts.get("contract_probe.invoice.fixture.issue") == Some(&8)
                && counts.get("invoice.ksef.ensure_accepted") == Some(&2)
        }
        _ => false,
    };

    if !complete {
        return Err(BrokerError::denied(
            "supervised probe did not consume its exact effect budget",
        ));
    }

    Ok(())
}

#[cfg(all(target_os = "linux", feature = "network"))]
fn verify_remote_accounts(
    plan: &NativeProbePlan,
    credentials: &ProviderCredentialSet,
    transport: &UreqProviderTransport,
) -> BrokerResult<()> {
    let (evidence_contract, connect_timeout_ms, request_timeout_ms) = match plan {
        NativeProbePlan::InvoiceIdentity(plan) => (
            "fakturownia-invoice-identity-s0.3-v1",
            plan.limits.connect_timeout_ms,
            plan.limits.request_timeout_ms,
        ),
        NativeProbePlan::KsefDemo(plan) => (
            "fakturownia-ksef-demo-s0.4-v1",
            plan.limits.connect_timeout_ms,
            plan.limits.request_timeout_ms,
        ),
    };

    for (index, credential) in credentials.targets.iter().enumerate() {
        let proposal = ReadObservationProposal {
            contract: ReadObservationProposal::CONTRACT.to_owned(),
            version: WIRE_VERSION.to_owned(),
            evidence_contract: evidence_contract.to_owned(),
            observation_id: format!("{index:032x}"),
            profile: credential.profile.clone(),
            target_key: credential.target_key.clone(),
            capability: "account.read".to_owned(),
            http_method: "GET".to_owned(),
            endpoint_template: "/account.json".to_owned(),
            provider_path: "/account.json".to_owned(),
            connect_timeout_ms,
            request_timeout_ms,
            maximum_response_bytes: 65_536,
        };
        proposal.validate(evidence_contract)?;
        let observation = transport.observe(credential, &proposal);
        verify_account_observation(credential, &observation)?;
    }

    Ok(())
}

#[cfg(all(target_os = "linux", feature = "network"))]
fn load_trust_policy(
    policy: &NativeSupervisorPolicyEnvelope,
    broker_policy_sha256: &str,
) -> BrokerResult<SignedDocument<NativeBrokerTrustPolicyEnvelope>> {
    let bytes = read_trusted_file(
        Path::new(&policy.trust_policy_path),
        MAXIMUM_POLICY_BYTES,
        false,
    )?;
    canonical::decode_object(&bytes)?;
    let document: SignedDocument<NativeBrokerTrustPolicyEnvelope> = serde_json::from_slice(&bytes)
        .map_err(|_| BrokerError::denied("native broker trust policy is invalid"))?;
    NativeBrokerTrustPolicyEnvelope::verify(
        &document,
        &policy.trust_policy_signer_id,
        &verifying_key(&policy.trust_policy_public_key)?,
        OffsetDateTime::now_utc(),
    )?;

    if !constant_time_hex_equal(
        &document.envelope.broker_policy_sha256,
        broker_policy_sha256,
    ) || document.envelope.probe_uid != policy.probe_uid
        || document.envelope.probe_gid != policy.probe_gid
        || document.envelope.supervisor_semantics_sha256 != policy.supervisor_semantics_sha256
        || document.envelope.argv_sha256 != policy.argv_sha256
        || document.envelope.environment_sha256 != policy.environment_sha256
    {
        return Err(BrokerError::denied(
            "native broker trust policy does not bind deployment policy",
        ));
    }

    Ok(document)
}

#[cfg(all(target_os = "linux", feature = "network"))]
fn load_authorization(
    policy: &NativeSupervisorPolicyEnvelope,
) -> BrokerResult<LoadedAuthorization> {
    let bytes = read_trusted_file(
        Path::new(&policy.authorization_bundle_path),
        MAXIMUM_AUTHORIZATION_BYTES,
        false,
    )?;
    canonical::decode_object(&bytes)?;
    let bundle: AuthorizationBundle = serde_json::from_slice(&bytes)
        .map_err(|_| BrokerError::denied("native authorization bundle is invalid"))?;
    let mut context = bundle.verify(
        &policy.authorization_signers,
        policy.maximum_authorization_ttl_seconds,
        &policy.launch_manifest_sha256,
        &policy.consumption_authority_trust(),
        OffsetDateTime::now_utc(),
    )?;
    context.profiles.sort();
    context.authorization_bundle_sha256 = sha256_hex(&bytes);
    context.probe_plan_sha256 = bundle.probe_plan.sha256()?;

    Ok(LoadedAuthorization { bundle, context })
}

#[cfg(all(target_os = "linux", feature = "network"))]
fn read_secret(
    path: &str,
    expected_sha256: &str,
    maximum_bytes: usize,
) -> BrokerResult<Zeroizing<Vec<u8>>> {
    let bytes = Zeroizing::new(read_trusted_file(Path::new(path), maximum_bytes, true)?);

    if !constant_time_hex_equal(expected_sha256, &sha256_hex(&bytes)) {
        return Err(BrokerError::denied(
            "native supervisor secret digest does not match policy",
        ));
    }

    Ok(bytes)
}

#[cfg(all(target_os = "linux", feature = "network"))]
fn read_document<T: for<'de> Deserialize<'de>>(reader: &mut impl std::io::Read) -> BrokerResult<T> {
    let object = frame::read(reader)?;
    serde_json::from_value(serde_json::Value::Object(object))
        .map_err(|_| BrokerError::denied("native supervisor wire document is invalid"))
}
