use std::fs::{self, File, OpenOptions};
use std::io::{Read, Write};
use std::path::{Path, PathBuf};

use serde::Serialize;

use crate::canonical;
use crate::{BrokerError, BrokerResult};

#[derive(Debug)]
pub struct ClaimedEffect {
    path: PathBuf,
    file: File,
}

impl ClaimedEffect {
    pub fn persist<T: Serialize>(mut self, record: &T) -> BrokerResult<PathBuf> {
        let bytes = canonical::encode(record)?;
        self.file
            .write_all(&bytes)
            .and_then(|()| self.file.sync_all())
            .map_err(|_| BrokerError::denied("cannot persist broker CAS record"))?;
        let parent = self
            .path
            .parent()
            .ok_or_else(|| BrokerError::denied("broker CAS path has no parent"))?;
        File::open(parent)
            .and_then(|directory| directory.sync_all())
            .map_err(|_| BrokerError::denied("cannot synchronize broker CAS directory"))?;

        Ok(self.path)
    }
}

pub fn claim(root: &Path, effect_id: &str) -> BrokerResult<Option<ClaimedEffect>> {
    if !is_effect_id(effect_id) {
        return Err(BrokerError::denied("broker effect identity is invalid"));
    }

    let metadata = fs::symlink_metadata(root)
        .map_err(|_| BrokerError::denied("broker CAS root is unavailable"))?;

    if !metadata.file_type().is_dir() {
        return Err(BrokerError::denied("broker CAS root is not a directory"));
    }

    let path = root.join(format!("{effect_id}.allocation.json"));
    let file = match OpenOptions::new().write(true).create_new(true).open(&path) {
        Ok(file) => file,
        Err(error) if error.kind() == std::io::ErrorKind::AlreadyExists => return Ok(None),
        Err(_) => return Err(BrokerError::denied("cannot allocate broker CAS record")),
    };

    Ok(Some(ClaimedEffect { path, file }))
}

pub fn read_record(
    root: &Path,
    effect_id: &str,
    suffix: &str,
    maximum_bytes: usize,
) -> BrokerResult<Option<Vec<u8>>> {
    if !is_effect_id(effect_id) || !matches!(suffix, "allocation" | "result") {
        return Err(BrokerError::denied("broker CAS record identity is invalid"));
    }

    let path = root.join(format!("{effect_id}.{suffix}.json"));
    let mut file = match OpenOptions::new().read(true).open(path) {
        Ok(file) => file,
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(None),
        Err(_) => return Err(BrokerError::denied("cannot read broker CAS record")),
    };
    let metadata = file
        .metadata()
        .map_err(|_| BrokerError::denied("cannot inspect broker CAS record"))?;

    if !metadata.file_type().is_file()
        || metadata.len() == 0
        || metadata.len() > maximum_bytes as u64
    {
        return Err(BrokerError::denied("broker CAS record shape is invalid"));
    }

    let capacity = usize::try_from(metadata.len())
        .map_err(|_| BrokerError::denied("broker CAS record is too large"))?;
    let mut bytes = Vec::with_capacity(capacity);
    file.read_to_end(&mut bytes)
        .map_err(|_| BrokerError::denied("cannot read complete broker CAS record"))?;

    Ok(Some(bytes))
}

pub fn store_record<T: Serialize>(
    root: &Path,
    effect_id: &str,
    suffix: &str,
    record: &T,
) -> BrokerResult<PathBuf> {
    if !is_effect_id(effect_id) || !matches!(suffix, "allocation" | "result") {
        return Err(BrokerError::denied("broker CAS record identity is invalid"));
    }

    let path = root.join(format!("{effect_id}.{suffix}.json"));
    let file = OpenOptions::new()
        .write(true)
        .create_new(true)
        .open(&path)
        .map_err(|_| BrokerError::denied("cannot allocate broker CAS record"))?;

    ClaimedEffect { path, file }.persist(record)
}

fn is_effect_id(value: &str) -> bool {
    value.len() == 32
        && value
            .bytes()
            .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase())
}

#[cfg(test)]
mod tests {
    use serde_json::json;
    use tempfile::tempdir;

    use super::claim;

    #[test]
    fn allocates_an_effect_exactly_once() -> Result<(), Box<dyn std::error::Error>> {
        let root = tempdir()?;
        let effect_id = "0123456789abcdef0123456789abcdef";
        let claimed_effect = claim(root.path(), effect_id)?.ok_or("first claim")?;
        let path = claimed_effect.persist(&json!({"effect_id": effect_id}))?;

        assert!(claim(root.path(), effect_id)?.is_none());
        assert_eq!(
            std::fs::read(path)?,
            format!("{{\"effect_id\":\"{effect_id}\"}}").as_bytes()
        );

        Ok(())
    }
}
