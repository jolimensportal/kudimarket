#!/usr/bin/env bash
# Provision the AWS side of a KudiMarket rehearsal: a locked-down security group, an address that will
# not move under you, and one server to put it all on.
#
# Usage:
#   bash scripts/aws-provision.sh                 # build it
#   bash scripts/aws-provision.sh --dry-run       # print every command, change nothing
#   bash scripts/aws-provision.sh --with-ssh      # also open port 22, from YOUR ip only
#   bash scripts/aws-provision.sh --teardown      # destroy everything this script created
#
# WHAT THIS SCRIPT DOES NOT DO
#
# It does not install the application. It does not open a database, publish a rate, or create an
# account. This is the floor -- the floor you cannot put a domain on top of without. Once this prints
# an IP, docs/production-runbook.md continues from there and scripts/deploy.sh does the rest.
#
# ONE MACHINE, ON PURPOSE
#
# PostgreSQL goes on the same box, not into RDS. scripts/deploy.sh provisions the database through
# `sudo -u postgres psql` on the local socket, and that has no remote branch -- RDS would need a code
# change to the deploy script before it could run at all. For a rehearsal that is not yet licensed to
# take money, one box that costs almost nothing against your credits and can be destroyed in a minute
# is worth more than managed durability you cannot use yet. Move to RDS when the licence clears.
#
# WHY eu-north-1 AND NOT ANYWHERE ELSE
#
# This project is Region-pinned to Stockholm by a service control policy; eu-central-1 is denied. Every
# resource below is created there deliberately. Do not "helpfully" pass --region to something else.
set -euo pipefail

REGION=eu-north-1
NAME=kudimarket

# Tag key and value are SEPARATE variables, and they have to be. The obvious-looking shortcut
# TAG="KudiMarket=rehearsal" expands into the filter "Name=tag:KudiMarket=rehearsal", which matches
# nothing at all: silently. Every "does it already exist" check below then answers no forever, so each
# run allocates another Elastic IP, and --teardown -- which finds its work by the same tag -- finds
# nothing to clean up. A tag is a key and a value; keep them apart.
TAG_KEY=KudiMarket
TAG_VALUE=rehearsal
FILTER="Name=tag:$TAG_KEY,Values=$TAG_VALUE"

SSH_ACCESS=0
DRY_RUN=0
TEARDOWN=0
INSTANCE_TYPE=${INSTANCE_TYPE:-t3.small}   # 2 GiB. t3.micro is 1 GiB and Postgres plus PHP-FPM will not live in it.

for arg in "$@"; do
  case "$arg" in
    --dry-run)  DRY_RUN=1 ;;
    --with-ssh) SSH_ACCESS=1 ;;
    --teardown) TEARDOWN=1 ;;
    -h|--help)  sed -n '2,25p' "$0"; exit 0 ;;
    *) printf 'unknown argument: %s (try --help)\n' "$arg" >&2; exit 1 ;;
  esac
done

# A command that either prints itself or runs itself. Dry-run and real work are the same code path, so
# what you read is exactly what runs -- there is no second, shorter version to drift out of step.
run() {
  if [ "$DRY_RUN" -eq 1 ]; then
    printf '    would run: %s\n' "$*" >&2
    return 0
  fi
  "$@"
}

# The one command whose output we actually consume, so it is echoed rather than swallowed.
#
# The echo goes to STDERR, and that is not cosmetic. This function is called inside $( ) to capture an
# answer, and a diagnostic printed to stdout joins that answer -- so VPC=$(runq aws ...) came back as
# "    aws ec2 describe-vpcs ... vpc-0e29f..." and the next command failed parsing its own filters.
# Anything a command substitution captures must go to stderr.
runq() {
  if [ "$DRY_RUN" -eq 1 ]; then
    printf '    would run: %s\n' "$*" >&2
    printf '    (dry-run cannot show the answer; stopping here)\n' >&2
    exit 2
  fi
  printf '    %s\n' "$*" >&2
  "$@"
}

# Fail on the literal "None" that the CLI prints when a query matches nothing. Passing that string on as
# an id produces "The vpc ID 'None' is malformed" a step later, which tells you nothing about the real
# problem -- an empty result set.
need_value() {
  [ -n "$2" ] && [ "$2" != "None" ] || { printf '%s is empty (no match?)\n' "$1" >&2; exit 1; }
}

step() { printf '\n\033[1m== %s ==\033[0m\n' "$*"; }
need() { command -v "$1" >/dev/null 2>&1 || { printf '%s is required.\n' "$1" >&2; exit 1; }; }

need aws
aws sts get-caller-identity >/dev/null 2>&1 || { printf 'credentials expired. Run: aws login\n' >&2; exit 1; }
ACCOUNT=$(aws sts get-caller-identity --query Account --output text)
export AWS_REGION="$REGION" AWS_DEFAULT_REGION="$REGION"

# ---------------------------------------------------------------- teardown

if [ "$TEARDOWN" -eq 1 ]; then
  step "Tearing down everything tagged $TAG_KEY=$TAG_VALUE in $REGION"

  # Terminate first, release the address second. Releasing an address still attached to an instance
  # fails, and doing it in the other order leaves the instance without the IP you gave your DNS record.
  for id in $(aws ec2 describe-instances \
      --filters "$FILTER" \
                "Name=instance-state-name,Values=pending,running,stopping,stopped" \
      --query 'Reservations[].Instances[].InstanceId' --output text); do
    [ "$id" != "None" ] || continue
    printf '  terminating %s\n' "$id"
    run aws ec2 terminate-instances --instance-ids "$id" --no-dry-run || true
  done

  # Give the instances a moment to actually release before reclaiming the address.
  [ "$DRY_RUN" -eq 1 ] || sleep 20

  for id in $(aws ec2 describe-addresses --filters "$FILTER" \
      --query 'Addresses[].AllocationId' --output text); do
    [ "$id" != "None" ] || continue
    printf '  releasing %s\n' "$id"
    run aws ec2 release-address --allocation-id "$id"
  done

  for id in $(aws ec2 describe-security-groups --filters "$FILTER" \
      --query 'SecurityGroups[].GroupId' --output text); do
    printf '  deleting security group %s\n' "$id"
    run aws ec2 delete-security-group --group-id "$id"
  done

  for id in $(aws ec2 describe-launch-templates --filters "Name=tag:$TAG_KEY,Values=$NAME" \
      --query 'LaunchTemplates[].LaunchTemplateId' --output text); do
    printf '  deleting launch template %s\n' "$id"
    run aws ec2 delete-launch-template --launch-template-id "$id"
  done

  printf '\nTeardown complete. Check with:\n  aws ec2 describe-instances --filters %s\n' "$FILTER"
  exit 0
fi

# ---------------------------------------------------------------- create

printf 'Account %s, region %s.\n' "$ACCOUNT" "$REGION"
if [ "$DRY_RUN" -eq 1 ]; then
  printf '\033[33mDRY RUN. Nothing below will be created.\033[0m\n'
else
  printf '\033[33mThis creates billable resources in your account. Stop now if that is not what you want.\033[0m\n'
fi

step "Finding the default VPC and a subnet"
# The filter is `is-default`, hyphenated. `isDefault` is accepted by nothing and returns no match
# silently, which then feeds the literal string "None" into the next command as if it were a VPC id.
VPC=$(runq aws ec2 describe-vpcs --filters Name=is-default,Values=true --query 'Vpcs[0].VpcId' --output text)
need_value "default VPC" "$VPC"
SUBNET=$(runq aws ec2 describe-subnets --filters "Name=vpc-id,Values=$VPC" "Name=default-for-az,Values=true" \
  --query 'Subnets[0].SubnetId' --output text)
need_value "default subnet" "$SUBNET"
printf '  VPC %s, subnet %s\n' "$VPC" "$SUBNET"

step "Security group $NAME-web"
# Deliberately NOT the default group. The default group is the one people open to 0.0.0.0/0 and then
# forget about; this one carries a name that says what it is for.
EXISTING_SG=$(aws ec2 describe-security-groups --filters "Name=group-name,Values=$NAME-web" \
  --query 'SecurityGroups[0].GroupId' --output text 2>/dev/null || echo None)

if [ "$EXISTING_SG" = "None" ] || [ -z "$EXISTING_SG" ]; then
  SG=$(runq aws ec2 create-security-group --group-name "$NAME-web" \
    --description "KudiMarket rehearsal: HTTPS in, nothing else" --vpc-id "$VPC" \
    --tag-specifications "ResourceType=security-group,Tags=[{Key=Name,Value=$NAME-web},{Key=$TAG_KEY,Value=$TAG_VALUE}]" \
    --query GroupId --output text)
  need_value "new security group id" "$SG"
  # 443 open to the world is the point of the thing. 22 is NOT opened here on purpose -- the default
  # path to the box is Systems Manager Session Manager, which needs no inbound port, no key pair, and
  # leaves a CloudTrail record. Opening SSH to the world is the single most-exploited mistake in cloud
  # and there is no reason to make it here.
  run aws ec2 authorize-security-group-ingress --group-id "$SG" --ip-permissions \
    'IpProtocol=tcp,FromPort=443,ToPort=443,IpRanges=[{CidrIp=0.0.0.0/0,Description=HTTPS}]' >/dev/null
else
  SG="$EXISTING_SG"
  printf '  reusing %s\n' "$SG"
fi

if [ "$SSH_ACCESS" -eq 1 ]; then
  printf '\n  Opening SSH to this machine only.\n'
  MY_IP=$(runq curl -s https://checkip.amazonaws.com | tr -d '[:space:]')
  run aws ec2 authorize-security-group-ingress --group-id "$SG" --ip-permissions \
    "IpProtocol=tcp,FromPort=22,ToPort=22,IpRanges=[{CidrIp=$MY_IP/32,Description=SSH-from-$MY_IP}]" >/dev/null
fi

step "Elastic IP (a stable address for your DNS record)"
# Allocated BEFORE the instance and attached to it. Without this, the first stop/start hands the box a
# new public IP and quietly breaks both your A record and the certificate you issued against it.
EXISTING_ALLOC=$(aws ec2 describe-addresses --filters "$FILTER" \
  --query 'Addresses[0].AllocationId' --output text 2>/dev/null || echo None)

if [ "$EXISTING_ALLOC" = "None" ] || [ -z "$EXISTING_ALLOC" ]; then
  ALLOC=$(runq aws ec2 allocate-address --domain vpc --tag-specifications \
    "ResourceType=elastic-ip,Tags=[{Key=Name,Value=$NAME-ip},{Key=$TAG_KEY,Value=$TAG_VALUE}]" \
    --query AllocationId --output text)
  need_value "new elastic ip allocation" "$ALLOC"
  printf '  allocation %s\n' "$ALLOC"
else
  ALLOC="$EXISTING_ALLOC"
  printf '  reusing %s\n' "$ALLOC"
fi

step "Session Manager access (no SSH, no key pair)"
# The instance profile is what lets the box call Systems Manager without any credential on disk. It is
# put INSIDE the launch template, not passed to run-instances: that command has no --instance-profile
# flag, and a profile supplied only at launch time is not reproducible from the template.
ROLE=$(aws iam get-role --role-name "$NAME-ssm" --query Role.RoleName --output text 2>/dev/null || echo None)
if [ "$ROLE" = "None" ] || [ -z "$ROLE" ]; then
  run aws iam create-role --role-name "$NAME-ssm" \
    --assume-role-policy-document '{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Service":"ec2.amazonaws.com"},"Action":"sts:AssumeRole"}]}' \
    --tags "Key=Name,Value=$NAME-ssm"
  run aws iam attach-role-policy --role-name "$NAME-ssm" \
    --policy-arn arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore
  run aws iam create-instance-profile --instance-profile-name "$NAME-ssm" --tags "Key=Name,Value=$NAME-ssm"
  run aws iam add-role-to-instance-profile --instance-profile-name "$NAME-ssm" --role-name "$NAME-ssm"
else
  printf '  reusing role %s\n' "$ROLE"
fi
PROF_ARN=$(runq aws iam get-instance-profile --instance-profile-name "$NAME-ssm" \
  --query 'InstanceProfile.Arn' --output text)
need_value "instance profile arn" "$PROF_ARN"

step "Launch template (so the instance is reproducible)"
# A launch template, not a launch configuration -- configurations are deprecated and new accounts
# cannot create them. IMDSv2 required: without it, anything that can make the instance issue a request
# can read its credentials, which is the classic SSRF-to-cloud-credential theft.
# The AMI is looked up by SSM parameter rather than hardcoded, because an AMI id that goes stale is a
# launch that fails with a confusing "InvalidAMIID.NotFound".
AMI=$(runq aws ssm get-parameter \
  --name /aws/service/canonical/ubuntu/server/24.04/stable/current/amd64/hvm/ebs-gp3/ami-id \
  --query Parameter.Value --output text)
need_value "ubuntu 24.04 AMI id" "$AMI"

# User data is a plain base64 STRING here. {"Fn::Base64": ...} is the CloudFormation wrapper and is
# rejected by --launch-template-data, which is not CloudFormation. KeyName is omitted rather than set to
# null for the same reason: a null where a string is expected fails validation, and this box is reached
# through Session Manager, so it has no key pair at all.
USERDATA=$(printf '#!/bin/bash\necho kudi-ready > /tmp/kudi-ready\n' | base64 | tr -d '\n')
LT_DATA=$(cat <<JSON
{
  "ImageId": "$AMI",
  "InstanceType": "$INSTANCE_TYPE",
  "SecurityGroupIds": ["$SG"],
  "IamInstanceProfile": {"Arn": "$PROF_ARN"},
  "MetadataOptions": {"HttpTokens": "required", "HttpEndpoint": "enabled", "HttpPutResponseHopLimit": 1},
  "BlockDeviceMappings": [{"DeviceName": "/dev/sda1", "Ebs": {"VolumeSize": 20, "VolumeType": "gp3", "Encrypted": true, "DeleteOnTermination": true}}],
  "Monitoring": {"Enabled": true},
  "UserData": "$USERDATA"
}
JSON
)

# Bump a version rather than replacing, so a template that already launched an instance stays intact.
# Replacing it in place would silently change what "reproducible" means.
LT_VER=$(aws ec2 describe-launch-templates --filters "Name=launch-template-name,Values=$NAME" \
  --query 'LaunchTemplates[0].LatestVersionNumber' --output text 2>/dev/null || echo 0)
[ "$LT_VER" = "None" ] && LT_VER=0
run aws ec2 create-launch-template-version --launch-template-name "$NAME" \
  --launch-template-data "$LT_DATA" --source-version "$LT_VER" >/dev/null
LT_VER=$((LT_VER + 1))
printf '  %s v%s (AMI %s, %s, IMDSv2 required, 20 GiB gp3 encrypted)\n' "$NAME" "$LT_VER" "$AMI" "$INSTANCE_TYPE"

step "The instance"
EXISTING_INSTANCE=$(aws ec2 describe-instances \
  --filters "$FILTER" "Name=instance-state-name,Values=pending,running,stopping,stopped" \
  --query 'Reservations[].Instances[0].InstanceId' --output text 2>/dev/null || echo None)

if [ "$EXISTING_INSTANCE" = "None" ] || [ -z "$EXISTING_INSTANCE" ]; then
  INSTANCE=$(runq aws ec2 run-instances \
    --launch-template "LaunchTemplateName=$NAME,Version=$LT_VER" \
    --subnet-id "$SUBNET" \
    --tag-specifications "ResourceType=instance,Tags=[{Key=Name,Value=$NAME-web},{Key=$TAG_KEY,Value=$TAG_VALUE}]" \
    --query Instances[0].InstanceId --output text)
  need_value "new instance id" "$INSTANCE"

  printf '  waiting for running'
  for _ in $(seq 1 60); do
    STATE=$(aws ec2 describe-instances --instance-ids "$INSTANCE" --query 'Instances[0].State.Name' --output text)
    [ "$STATE" = "running" ] && break
    printf '.'
    sleep 5
  done
  printf '\n'
else
  INSTANCE="$EXISTING_INSTANCE"
  printf '  reusing %s\n' "$INSTANCE"
fi

step "Associating the Elastic IP"
run aws ec2 associate-address --instance-id "$INSTANCE" --allocation-id "$ALLOC" \
  || printf '  (already associated, or dry-run -- this is safe to re-run)\n'

sleep 5
PUBLIC_IP=$(runq aws ec2 describe-instances --instance-ids "$INSTANCE" \
  --query 'Instances[0].PublicIpAddress' --output text)
need_value "public ip" "$PUBLIC_IP"

printf '\n\033[1m== What to do next ==\033[0m\n'
printf '\n1. Point DNS at this address, NOW, before anything else:\n'
printf '     A     kudimarket.com     %s\n' "$PUBLIC_IP"
printf '     A     www.kudimarket.com %s   (only if you intend to serve it)\n' "$PUBLIC_IP"
printf '   Do this in the registrar where you bought the name.\n'
printf '\n2. Wait ~60s for the SSM agent, then open a shell WITHOUT SSH:\n'
printf '     aws ssm start-session --target %s\n' "$INSTANCE"
printf '\n3. From that shell, continue with docs/production-runbook.md section 2 onward:\n'
printf '     packages -> repo -> .env -> bash scripts/deploy.sh /srv/kudimarket\n'
printf '\n4. Watch what this is costing:\n'
printf '     aws ce get-cost-and-usage --time-period Start=$(date -u +%%Y-%%m-%%d),End=$(date -u +%%Y-%%m-%%d) --granularity DAILY\n'
printf '\n5. To destroy all of it (this reclaims the credits):\n'
printf '     bash scripts/aws-provision.sh --teardown\n'
printf '\nEverything above is tagged %s=%s. Teardown finds it by tag, not by name, so it cannot\n' "$TAG_KEY" "$TAG_VALUE"
printf 'delete something you made yourself.\n'
