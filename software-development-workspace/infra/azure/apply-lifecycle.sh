#!/usr/bin/env bash
#
# Apply the document retention policy to the storage account.
#
# WHAT IT DOES
#
# Deletes anything in `hrms-candidate-docs` 180 days after upload — CVs and
# application attachments both. Previous versions and snapshots are cleared 30
# days after they are created, because versioning is enabled on this account
# and a rule that deletes only current versions leaves every superseded copy
# behind forever. A retention promise that keeps old versions is not a
# retention promise.
#
# No other container is touched. Employee documents, preboarding paperwork,
# training evidence and database backups have different obligations, and the
# statutory minimum for employment records is measured in years.
#
# READ THIS BEFORE RUNNING — IT DELETES REAL DATA ON A SCHEDULE
#
# 1. HIRED CANDIDATES. The rule cannot tell a rejected applicant's CV from the
#    CV of somebody you employed. Six months after their application, both are
#    deleted. If a hired candidate's CV forms part of their personnel file,
#    that file loses a document, silently, months later, with no failure
#    anywhere to notice.
#
#    The fix is in the application, not here: copy the CV into
#    hrms-employee-documents when a candidate is converted to an employee.
#    That container has no expiry rule. Until that exists, running this policy
#    means accepting the loss.
#
# 2. ACTUAL ERASURE IS LATER THAN 180 DAYS. Blob soft delete is on with a
#    30-day window, so a lifecycle deletion is recoverable for a further 30
#    days before it is purged. If "we delete CVs after six months" is a
#    commitment you have made to candidates or to the regulator, the honest
#    figure is 210 days to irreversible erasure.
#
# 3. LIFECYCLE RULES ARE NOT IMMEDIATE. Azure evaluates them roughly daily,
#    and the first run can take up to 48 hours to appear.
#
# Run as an admin: this is a control-plane operation, and the application
# holds data-plane roles only.
#
#   az-user login
#   AZ=az-user ./apply-lifecycle.sh
set -euo pipefail

AZ="${AZ:-az}"
RG="Kon1"
ACCOUNT="knems"
POLICY="$(dirname "$0")/lifecycle-policy.json"

[[ -r "$POLICY" ]] || { echo "cannot read $POLICY" >&2; exit 1; }

echo "About to apply this retention policy to ${ACCOUNT}:"
echo
python3 -c "
import json, sys
p = json.load(open('$POLICY'))
for r in p['rules']:
    d = r['definition']
    print('  rule    :', r['name'], '(enabled)' if r['enabled'] else '(disabled)')
    print('  applies :', ', '.join(d['filters']['prefixMatch']))
    for kind, act in d['actions'].items():
        for verb, cond in act.items():
            days = list(cond.values())[0]
            print(f'  {kind:<8}: {verb} {days} days after creation')
"
echo
cat <<'WARN'
  This deletes real candidate data on a schedule, including the CVs of people
  you hired unless the application copies those into the employee container
  first. Nothing will alert you when it happens.
WARN
echo
read -r -p "Type APPLY to continue: " reply
[[ "$reply" == "APPLY" ]] || { echo "aborted"; exit 1; }

"$AZ" storage account management-policy create \
  --account-name "$ACCOUNT" -g "$RG" \
  --policy "@${POLICY}" -o none

echo
echo "Applied. Current policy:"
"$AZ" storage account management-policy show --account-name "$ACCOUNT" -g "$RG" -o json \
  | python3 -c "import json,sys; print(json.dumps(json.load(sys.stdin).get('policy', {}), indent=2))"

cat <<'NEXT'

Azure evaluates lifecycle rules about once a day, and the first evaluation can
take up to 48 hours, so nothing will appear to happen immediately.

Nothing currently in the container is old enough to be affected: the earliest
candidate documents were uploaded in 2026-08.
NEXT
