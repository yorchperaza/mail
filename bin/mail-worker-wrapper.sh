#!/bin/bash
# bin/mail-worker-wrapper.sh

# Set environment variables
export REDIS_HOST=10.0.0.164
export REDIS_PORT=6379
export REDIS_DB=0
export REDIS_AUTH=S3cureRedisPa55!
export REDIS_URL="redis://:S3cureRedisPa55!@10.0.0.164:6379/0"

export SMTP_HOST=smtp.monkeysmail.com
export SMTP_PORT=587
export SMTP_SECURE=tls
export SMTP_USERNAME=smtpuser
export SMTP_PASSWORD=S3cureP@ssw0rd

export MAIL_STREAM=mail:outbound
export MAIL_GROUP=senders

# Run the worker
exec php /var/www/smtp.monkeysmail.com/bin/mail-worker.php