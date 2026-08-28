use base64::Engine;
use base64::engine::general_purpose::STANDARD;
use ed25519_dalek::{Signature, Signer, SigningKey, Verifier, VerifyingKey};
use hmac::{Hmac, Mac};
use sha2::{Digest, Sha256};
use subtle::ConstantTimeEq;

use crate::{BrokerError, BrokerResult};

type HmacSha256 = Hmac<Sha256>;

#[must_use]
pub fn sha256_hex(bytes: &[u8]) -> String {
    format!("{:x}", Sha256::digest(bytes))
}

pub fn hmac_sha256_hex(key: &[u8], bytes: &[u8]) -> BrokerResult<String> {
    let mut hmac = HmacSha256::new_from_slice(key)
        .map_err(|_| BrokerError::denied("broker HMAC key is invalid"))?;
    hmac.update(bytes);

    Ok(format!("{:x}", hmac.finalize().into_bytes()))
}

#[must_use]
pub fn constant_time_hex_equal(expected: &str, actual: &str) -> bool {
    expected.len() == actual.len() && expected.as_bytes().ct_eq(actual.as_bytes()).into()
}

pub fn decode_canonical_base64(value: &str, exact_bytes: usize) -> BrokerResult<Vec<u8>> {
    let decoded = STANDARD
        .decode(value)
        .map_err(|_| BrokerError::denied("broker base64 value is invalid"))?;

    if decoded.len() != exact_bytes || STANDARD.encode(&decoded) != value {
        return Err(BrokerError::denied("broker base64 value is not canonical"));
    }

    Ok(decoded)
}

pub fn verifying_key(value: &str) -> BrokerResult<VerifyingKey> {
    let bytes: [u8; 32] = decode_canonical_base64(value, 32)?
        .try_into()
        .map_err(|_| BrokerError::denied("broker verifying key has an invalid length"))?;
    VerifyingKey::from_bytes(&bytes)
        .map_err(|_| BrokerError::denied("broker verifying key is invalid"))
}

pub fn signing_key(seed: &[u8]) -> BrokerResult<SigningKey> {
    let seed: [u8; 32] = seed
        .try_into()
        .map_err(|_| BrokerError::denied("broker signing seed has an invalid length"))?;

    Ok(SigningKey::from_bytes(&seed))
}

#[must_use]
pub fn sign_base64(key: &SigningKey, message: &[u8]) -> String {
    STANDARD.encode(key.sign(message).to_bytes())
}

pub fn verify_base64(key: &VerifyingKey, message: &[u8], signature: &str) -> BrokerResult<()> {
    let bytes: [u8; 64] = decode_canonical_base64(signature, 64)?
        .try_into()
        .map_err(|_| BrokerError::denied("broker signature has an invalid length"))?;
    let signature = Signature::from_bytes(&bytes);
    key.verify(message, &signature)
        .map_err(|_| BrokerError::denied("broker signature verification failed"))
}

#[cfg(test)]
mod tests {
    use ed25519_dalek::SigningKey;
    use rand::rngs::OsRng;

    use super::{sign_base64, verify_base64};

    #[test]
    fn signs_and_verifies_detached_ed25519() -> Result<(), Box<dyn std::error::Error>> {
        let key = SigningKey::generate(&mut OsRng);
        let signature = sign_base64(&key, b"canonical envelope");

        verify_base64(&key.verifying_key(), b"canonical envelope", &signature)?;
        assert!(verify_base64(&key.verifying_key(), b"other envelope", &signature).is_err());

        Ok(())
    }
}
