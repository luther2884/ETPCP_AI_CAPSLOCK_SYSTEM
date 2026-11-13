# Execution Scripts (Layer 3)

This directory contains deterministic Python scripts used by the orchestration agent to perform reliable operations (API integrations, database manipulation, file processing, data transformation).

## Guidelines
- Scripts must be standalone and deterministic.
- Read environment configuration from `.env`.
- Output clear status, logging, and error tracebacks.
