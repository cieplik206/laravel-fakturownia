use std::fmt::{Display, Formatter};

#[derive(Debug)]
pub struct BrokerError {
    public_message: &'static str,
}

impl BrokerError {
    #[must_use]
    pub const fn denied(public_message: &'static str) -> Self {
        Self { public_message }
    }

    #[must_use]
    pub const fn public_message(&self) -> &'static str {
        self.public_message
    }
}

impl Display for BrokerError {
    fn fmt(&self, formatter: &mut Formatter<'_>) -> std::fmt::Result {
        formatter.write_str(self.public_message)
    }
}

impl std::error::Error for BrokerError {}

pub type BrokerResult<T> = Result<T, BrokerError>;
