# EPANET command-line engine

- Project: OpenWaterAnalytics/EPANET
- Version: 2.3.5 (Windows 64-bit)
- Release: https://github.com/OpenWaterAnalytics/EPANET/releases/tag/v2.3.5
- License: MIT (`LICENSE.txt`)

SHA-256:

- `runepanet.exe`: `BE4C6FA06B11B51F6201C5D4057F0A4A344AAFE865B9C324C1A8FF2B47611097`
- `epanet2.dll`: `0FEC08B4BA0320A08E821E4E79ED380CE09AA926867A2B1D33E878B6159C02FA`

The web application invokes `runepanet.exe` with an application-generated
`.inp` file. Runtime files are written under `storage/hydraulic/`; input data in
the operational database is never modified by the engine.
