# Product Date Checker Script

A professional PHP script that monitors product dates from multiple URLs and sends email notifications when dates change.

## Features

- **Multi-URL Monitoring**: Checks 4 product URLs simultaneously
- **Date Extraction**: Extracts dates from product titles in format DDMMYYYY_HHMMSS
- **Daily Logging**: Single log file with 3-day history
- **Change Detection**: Compares current dates with most recent historical data (not just yesterday)
- **Email Notifications**: Sends HTML email reports via SMTP to multiple recipients
- **Professional Reports**: Beautiful responsive HTML table with site names
- **Mobile Responsive**: Strict mobile-first design for all screen sizes
- **Simple & Clean**: No complexity, just what's needed

## Monitored URLs

1. https://assuredperformance.ie/dbsc99a
2. https://www.ebs.co.uk/dbsc99a
3. https://www.aastb.com/dbsc99a-xray
4. https://ebs-europe.nl/dbsc99a-hol

## Setup

### Prerequisites

- PHP 7.0 or higher
- cURL extension enabled
- DOM extension enabled

### Configuration

The script uses environment variables from `.env` file:

```
SMTP_USER='your-email@example.com'
SMTP_PASS='your-password'
SMTP_HOSTNAME='smtp.gmail.com'
SMTP_PORT=465
RECIPIENT_EMAIL='sadiq.muneeb1234@gmail.com,masumbinshaukat@gmail.com'
```

**Note**: For multiple recipients, separate email addresses with commas in `RECIPIENT_EMAIL`

## Usage

### Manual Execution

```bash
php check_dates.php
```

### Cron Job Setup

To run daily at 10:00 AM (server time):

```bash
0 10 * * * cd /path/to/date_checker_script && php check_dates.php >> /path/to/date_checker_script/cron.log 2>&1
```

Or for Windows Task Scheduler:
- Program: `C:\xampp\php\php.exe`
- Arguments: `C:\xampp\htdocs\date_checker_script\check_dates.php`
- Start in: `C:\xampp\htdocs\date_checker_script`

## How It Works

1. **Fetch**: Script fetches HTML from all product URLs
2. **Extract**: Parses product titles to extract dates (format: DDMMYYYY_HHMMSS)
3. **Log**: Updates single log file `logs/product_dates.json` with today's data
4. **Compare**: Compares with most recent historical data (not just yesterday's)
5. **Notify**: Sends responsive HTML email with table showing site names, links, dates, and status
6. **Cleanup**: Automatically keeps only last 3 days of history

## Log File

Single log file: `logs/product_dates.json` with 3-day history

Example log structure:
```json
{
    "last_updated": "2025-10-02 18:30:31",
    "history": {
        "2025-10-01": {
            "timestamp": "2025-10-01 18:25:59",
            "data": { ... },
            "errors": []
        },
        "2025-10-02": {
            "timestamp": "2025-10-02 18:30:31",
            "data": { ... },
            "errors": []
        }
    }
}
```

- Keeps last 3 days only
- Auto-cleanup of old data
- Single file, not multiple daily files

## Email Reports

The script sends professional HTML emails with a responsive table containing:

### Table Columns
1. **Site**: Website name (e.g., "Assuredperformance", "Ebs", "Aastb")
2. **Product Link**: Clickable URL to the product
3. **Old Date**: Previous day's date
4. **Current Date**: Today's date
5. **Status**: ✓ (green) if changed, ✗ (red) if unchanged

### Email Types

**When Changes Detected**
- Subject: "⚠️ Product Date Changes Detected - YYYY-MM-DD"
- Summary shows count of changed vs unchanged products
- Changed products marked with green ✓

**When No Changes**
- Subject: "✓ Product Date Check - No Changes - YYYY-MM-DD"
- All products marked with red ✗
- Confirms monitoring is active

## Troubleshooting

### SMTP Connection Issues
- Verify SMTP credentials in `.env`
- Check if port 465 (SSL) is accessible
- Ensure firewall allows outbound SMTP connections

### Date Not Found
- Verify product page structure hasn't changed
- Check if h1 tag contains date in format DDMMYYYY_HHMMSS
- Review error logs in daily JSON files

### If dates not comparing
- Script now uses most recent historical data, not just yesterday's
- Ensure at least one previous run exists in logs
- Check log file has historical data
- Verify system date is correct

### Permission Issues
- Ensure `logs/` directory is writable
- Check PHP has permission to create files
## License

This script is provided as-is for internal use.
