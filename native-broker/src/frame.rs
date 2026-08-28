use std::io::{Read, Write};

use serde::Serialize;
use serde_json::{Map, Value};

use crate::canonical;
use crate::{BrokerError, BrokerResult};

pub const MAXIMUM_PAYLOAD_BYTES: usize = 36_700_160;
const HEADER_BYTES: usize = 9;

pub fn write<T: Serialize>(writer: &mut impl Write, document: &T) -> BrokerResult<()> {
    let payload = canonical::encode(document)?;

    if payload.is_empty() || payload.len() > MAXIMUM_PAYLOAD_BYTES {
        return Err(BrokerError::denied(
            "native broker frame exceeds its payload limit",
        ));
    }

    let header = format!("{:08x}\n", payload.len());
    writer
        .write_all(header.as_bytes())
        .and_then(|()| writer.write_all(&payload))
        .and_then(|()| writer.flush())
        .map_err(|_| BrokerError::denied("cannot write native broker frame"))
}

pub fn read(reader: &mut impl Read) -> BrokerResult<Map<String, Value>> {
    let mut header = [0_u8; HEADER_BYTES];
    reader
        .read_exact(&mut header)
        .map_err(|_| BrokerError::denied("cannot read native broker frame header"))?;

    if header[8] != b'\n'
        || !header[..8]
            .iter()
            .all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(byte))
    {
        return Err(BrokerError::denied("native broker frame header is invalid"));
    }

    let length = usize::from_str_radix(
        std::str::from_utf8(&header[..8])
            .map_err(|_| BrokerError::denied("native broker frame header is invalid"))?,
        16,
    )
    .map_err(|_| BrokerError::denied("native broker frame header is invalid"))?;

    if length == 0 || length > MAXIMUM_PAYLOAD_BYTES {
        return Err(BrokerError::denied(
            "native broker frame exceeds its payload limit",
        ));
    }

    let mut payload = vec![0_u8; length];
    reader
        .read_exact(&mut payload)
        .map_err(|_| BrokerError::denied("cannot read complete native broker frame"))?;

    canonical::decode_object(&payload)
}

#[cfg(test)]
mod tests {
    use std::io::Cursor;

    use serde_json::json;

    use super::{read, write};

    #[test]
    fn matches_the_php_length_prefixed_contract() -> Result<(), Box<dyn std::error::Error>> {
        let mut frame = Vec::new();
        write(&mut frame, &json!({"b": 2, "a": 1}))?;

        assert_eq!(frame, b"0000000d\n{\"a\":1,\"b\":2}");
        assert_eq!(
            read(&mut Cursor::new(frame))?,
            json!({"a": 1, "b": 2})
                .as_object()
                .cloned()
                .ok_or("object")?
        );

        Ok(())
    }
}
