# Driving Experience Recorder

- Sessions start on every PHP entry point to support CSRF tokens and anonymised ID mappings. Session tokens for actions are stored in `$_SESSION['anon_map'][<type>][<code>] = <real_id>` with the reverse lookup in `$_SESSION['anon_reverse']`.
- The shared `src/DrivingExperience.php` class represents a driving session and is used when inserting, loading, and editing sessions.
- Summary statistics are now built with SQL JOIN-based aggregates (weather, traffic, roads, day part) rather than PHP loops.
- When running locally, record a session, edit/delete it via `experiences.php`, and confirm the summary charts load without SQL errors.
