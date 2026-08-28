#![forbid(unsafe_code)]

use fakturownia_native_broker::EXIT_CONFIGURATION;
use fakturownia_native_broker::supervisor::{self, CompiledTrust};

const POLICY_PATH: &str = "/etc/cieplik206/fakturownia-live-evidence/native-supervisor-policy.json";

fn main() {
    let exit_code = match run() {
        Ok(()) => 0,
        Err(error) => {
            eprintln!("native supervisor denied: {}", error.public_message());
            EXIT_CONFIGURATION
        }
    };

    std::process::exit(i32::from(exit_code));
}

fn run() -> fakturownia_native_broker::BrokerResult<()> {
    if std::env::args_os().len() != 1 {
        return Err(fakturownia_native_broker::BrokerError::denied(
            "native supervisor rejects every caller argument",
        ));
    }

    let deployment = option_env!("FAKTUROWNIA_NATIVE_BROKER_DEPLOYMENT").unwrap_or("");
    let signer_id = option_env!("FAKTUROWNIA_NATIVE_POLICY_SIGNER_ID").unwrap_or("");
    let public_key = option_env!("FAKTUROWNIA_NATIVE_POLICY_PUBLIC_KEY_BASE64").unwrap_or("");
    let semantics = option_env!("FAKTUROWNIA_NATIVE_SUPERVISOR_SEMANTICS_SHA256").unwrap_or("");

    if deployment != "reviewed-v1"
        || signer_id.is_empty()
        || public_key.is_empty()
        || semantics.len() != 64
    {
        return Err(fakturownia_native_broker::BrokerError::denied(
            "native supervisor deployment is not cryptographically provisioned",
        ));
    }

    supervisor::run(&CompiledTrust {
        policy_path: POLICY_PATH,
        expected_policy_signer_id: signer_id,
        policy_public_key_base64: public_key,
        supervisor_semantics_sha256: semantics,
    })
}
