#![forbid(unsafe_code)]

pub mod authorization;
pub mod broker;
pub mod canonical;
pub mod cas;
pub mod crypto;
pub mod error;
pub mod frame;
pub mod observation;
pub mod plan;
pub mod policy;
pub mod protocol;
pub mod result;
pub mod supervisor;
pub mod trust;

pub use error::{BrokerError, BrokerResult};

pub const EXIT_CONFIGURATION: u8 = 78;
