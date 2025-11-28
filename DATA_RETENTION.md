# Data Retention Policy

This document outlines data retention practices for the Liturgical Calendar API, particularly regarding
audit logs that may contain personal data subject to privacy regulations (GDPR, CCPA, etc.).

## Audit Logging

### Purpose

The API logs audit events for write operations (PUT, PATCH, DELETE) to track changes to calendar data.
This supports:

- Security monitoring and incident response
- Change tracking and accountability
- Debugging and troubleshooting

### Data Collected

Audit log entries include:

| Field       | Description                                   | Personal Data |
|-------------|-----------------------------------------------|---------------|
| `operation` | HTTP method (PUT, PATCH, DELETE)              | No            |
| `category`  | Calendar type (diocese, nation, widerregion)  | No            |
| `key`       | Calendar identifier                           | No            |
| `client_ip` | IP address of the request origin              | **Yes**       |
| `files`     | Affected file paths                           | No            |
| `datetime`  | Timestamp of the operation                    | No            |

### Retention Period

Audit logs are configured with a **90-day retention period** via rotating file handlers.

```php
// In RegionalDataHandler constructor
$this->auditLogger = LoggerFactory::create('audit', null, 90, false, true, false);
//                                                       ^^
//                                          maxFiles = 90 days of rotation
```

After 90 days, log files are automatically deleted by the Monolog rotating file handler.

### Storage Location

Audit logs are stored in:

```text
logs/audit.json-YYYY-MM-DD.log
```

Each day's logs are in a separate file in NDJSON (Newline Delimited JSON) format.

## Privacy Considerations

### IP Addresses as Personal Data

Under GDPR and similar regulations, IP addresses are considered personal data because they can
potentially identify individuals. The API collects client IP addresses for legitimate security
and auditing purposes.

### Legal Basis (GDPR)

The collection of IP addresses for audit logging may be justified under:

- **Article 6(1)(f)** - Legitimate interests: Security monitoring and protecting the integrity
  of calendar data
- **Article 6(1)(c)** - Legal obligation: If required for compliance with security standards

### Data Subject Rights

If operating in jurisdictions covered by privacy regulations, consider implementing:

1. **Right to Access**: Ability to search logs for a specific IP address
2. **Right to Erasure**: Process for anonymizing or deleting logs containing specific IPs
3. **Data Processing Records**: Document audit logging in your Records of Processing Activities (ROPA)

## Recommendations

### For Production Deployments

1. **Document the retention period** in your privacy policy
2. **Restrict log access** to authorized personnel only
3. **Consider log encryption** for sensitive environments
4. **Review retention period** based on your organization's requirements and applicable regulations

### Configuration Options

The retention period can be adjusted in `RegionalDataHandler.php`:

```php
// Change 90 to desired number of days
$this->auditLogger = LoggerFactory::create('audit', null, 30, false, true, false);
```

### Log Access Control

Ensure the `logs/` directory has appropriate permissions:

```bash
chmod 750 logs/
chown www-data:www-data logs/
```

## Related Files

- `src/Handlers/RegionalDataHandler.php` - Audit logging implementation
- `src/Http/Logs/LoggerFactory.php` - Logger factory with rotation configuration
- `src/Handlers/Auth/ClientIpTrait.php` - Client IP extraction logic

## Changelog

| Date    | Change                                                             |
|---------|--------------------------------------------------------------------|
| 2024-11 | Initial documentation of audit logging and 90-day retention policy |
