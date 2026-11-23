#!/usr/bin/env bash
# Weekly SSL watchdog for FieldWire hosting.
# - Checks HTTPS certs for the public FieldWire hosts
# - If fewer than RENEW_DAYS remain, triggers cPanel AutoSSL
# - Emails ALERT_TO only when the cert is still too close to expiry after renewal
#
# Cron (Sunday 04:15 America/Toronto server time):
#   15 4 * * 0 bash /home/yjyhtqh8/fwapi.medicalcontractor.ca/scripts/check-ssl-and-renew.sh >> /home/yjyhtqh8/fwapi.medicalcontractor.ca/logs/ssl-watchdog.log 2>&1

set -euo pipefail

HOSTS=(
  "fieldwire.medicalcontractor.ca"
  "fwapi.medicalcontractor.ca"
)
RENEW_DAYS=30
ALERT_DAYS=21
ALERT_TO="${SSL_ALERT_TO:-justin.kearney@carespace.ca,serg.kostyuk@gmail.com}"
LOG_PREFIX="[ssl-watchdog $(date -u +%Y-%m-%dT%H:%M:%SZ)]"

days_left_for_host() {
  local host="$1"
  local end_date
  end_date="$(
    echo | openssl s_client -connect "${host}:443" -servername "${host}" 2>/dev/null \
      | openssl x509 -noout -enddate 2>/dev/null \
      | cut -d= -f2
  )"
  if [[ -z "${end_date}" ]]; then
    echo "-1"
    return
  fi
  local end_epoch now_epoch
  end_epoch="$(date -d "${end_date}" +%s)"
  now_epoch="$(date +%s)"
  echo $(( (end_epoch - now_epoch) / 86400 ))
}

min_days=-1
report=""
for host in "${HOSTS[@]}"; do
  days="$(days_left_for_host "${host}")"
  report+="${host}: ${days} day(s) left"$'\n'
  if [[ "${min_days}" -lt 0 ]] || [[ "${days}" -lt "${min_days}" ]]; then
    min_days="${days}"
  fi
done

echo "${LOG_PREFIX} status:"$'\n'"${report}"

if [[ "${min_days}" -lt 0 ]]; then
  echo "${LOG_PREFIX} could not read certificate; triggering AutoSSL"
  /usr/bin/uapi SSL start_autossl_check >/dev/null || true
  sleep 90
elif [[ "${min_days}" -le "${RENEW_DAYS}" ]]; then
  echo "${LOG_PREFIX} <= ${RENEW_DAYS} days left; triggering AutoSSL"
  /usr/bin/uapi SSL start_autossl_check >/dev/null || true
  sleep 90
else
  echo "${LOG_PREFIX} ok (> ${RENEW_DAYS} days); no renew needed"
  exit 0
fi

min_days=-1
report=""
for host in "${HOSTS[@]}"; do
  days="$(days_left_for_host "${host}")"
  report+="${host}: ${days} day(s) left"$'\n'
  if [[ "${min_days}" -lt 0 ]] || [[ "${days}" -lt "${min_days}" ]]; then
    min_days="${days}"
  fi
done

echo "${LOG_PREFIX} after AutoSSL:"$'\n'"${report}"

if [[ "${min_days}" -lt 0 ]] || [[ "${min_days}" -le "${ALERT_DAYS}" ]]; then
  subject="FieldWire SSL needs attention (${min_days} days left)"
  body="$(cat <<EOF
FieldWire HTTPS certificate is close to expiry or could not be renewed automatically.

${report}
AutoSSL was triggered from the hosting account, but the certificate still needs attention.

Please contact WHC hosting support and ask them to renew SSL / AutoSSL for:
- fieldwire.medicalcontractor.ca
- fwapi.medicalcontractor.ca

Until SSL is valid, the login page may show "Network Error".
EOF
)"
  echo "${body}" | mail -s "${subject}" "${ALERT_TO}" || true
  echo "${LOG_PREFIX} alert emailed to ${ALERT_TO}"
  exit 1
fi

echo "${LOG_PREFIX} renewal looks healthy"
exit 0
