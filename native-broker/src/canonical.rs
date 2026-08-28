use serde::Serialize;
use serde_json::{Map, Value};

use crate::{BrokerError, BrokerResult};

pub fn encode<T: Serialize>(value: &T) -> BrokerResult<Vec<u8>> {
    let value = serde_json::to_value(value)
        .map_err(|_| BrokerError::denied("cannot canonicalize broker document"))?;
    let normalized = normalize(value)?;

    serde_json::to_vec(&normalized)
        .map_err(|_| BrokerError::denied("cannot encode canonical broker document"))
}

pub fn decode_object(bytes: &[u8]) -> BrokerResult<Map<String, Value>> {
    let value: Value = serde_json::from_slice(bytes)
        .map_err(|_| BrokerError::denied("broker document is not valid JSON"))?;
    let normalized = normalize(value.clone())?;
    let encoded = serde_json::to_vec(&normalized)
        .map_err(|_| BrokerError::denied("cannot encode canonical broker document"))?;

    if encoded != bytes {
        return Err(BrokerError::denied("broker document is not canonical JSON"));
    }

    match value {
        Value::Object(object) if !object.is_empty() => Ok(object),
        _ => Err(BrokerError::denied(
            "broker document must contain one JSON object",
        )),
    }
}

fn normalize(value: Value) -> BrokerResult<Value> {
    match value {
        Value::Null | Value::Bool(_) | Value::String(_) => Ok(value),
        Value::Number(number) if number.is_i64() || number.is_u64() => Ok(Value::Number(number)),
        Value::Number(_) => Err(BrokerError::denied(
            "canonical broker JSON forbids floating point numbers",
        )),
        Value::Array(values) => values
            .into_iter()
            .map(normalize)
            .collect::<BrokerResult<Vec<_>>>()
            .map(Value::Array),
        Value::Object(values) => {
            let mut entries = values.into_iter().collect::<Vec<_>>();
            entries.sort_by(|left, right| left.0.as_bytes().cmp(right.0.as_bytes()));
            let mut object = Map::new();

            for (key, value) in entries {
                object.insert(key, normalize(value)?);
            }

            Ok(Value::Object(object))
        }
    }
}

#[cfg(test)]
mod tests {
    use serde_json::json;

    use super::{decode_object, encode};

    #[test]
    fn sorts_every_object_recursively() -> Result<(), Box<dyn std::error::Error>> {
        let encoded = encode(&json!({"z": {"b": 2, "a": 1}, "a": true}))?;

        assert_eq!(encoded, br#"{"a":true,"z":{"a":1,"b":2}}"#);
        assert!(decode_object(&encoded).is_ok());
        assert!(decode_object(br#"{"z":1,"a":2}"#).is_err());

        Ok(())
    }
}
