#!/usr/bin/env bash
# Run interactively by the hosting owner. Never place credentials in arguments/history.
set -euo pipefail
set +x
umask 077
yard_home=/home/c/ch197840
yard_cms="$yard_home/public_html/cms"
cd "$yard_home"
test -f "$yard_cms/wp-load.php"
test -f "$yard_cms/wp-content/plugins/yard-projects/yard-projects.php"
if test -e "$yard_cms/wp-config.php"; then
  echo 'Configuration already exists. Stop and inspect before retrying.'
  exit 1
fi
while :; do
  read -r -s -p '1/3 Database password (hidden), then Enter: ' YARD_DATABASE_PASSWORD
  printf '\n'
  if test -n "$YARD_DATABASE_PASSWORD"; then break; fi
  echo 'EMPTY_DATABASE_PASSWORD: nothing was entered. Please try again.'
done
while :; do
  read -r -s -p '2/3 Choose NEW CMS password for yard-owner (12+ characters), then Enter: ' YARD_OWNER_PASSWORD
  printf '\n'
  if test "${#YARD_OWNER_PASSWORD}" -lt 12; then
    echo 'CMS_PASSWORD_TOO_SHORT: enter at least 12 characters. Please try again.'
    continue
  fi
  if test "$YARD_DATABASE_PASSWORD" = "$YARD_OWNER_PASSWORD"; then
    echo 'CMS_PASSWORD_REUSED: choose a password different from the database password.'
    continue
  fi
  read -r -s -p '3/3 Repeat CMS password, then Enter: ' YARD_OWNER_CONFIRM
  printf '\n'
  if test "$YARD_OWNER_PASSWORD" = "$YARD_OWNER_CONFIRM"; then break; fi
  unset YARD_OWNER_PASSWORD YARD_OWNER_CONFIRM
  echo 'CMS_PASSWORD_MISMATCH: the two entries differ. Enter the new CMS password twice again.'
done
unset YARD_OWNER_CONFIRM
wp config create --path="$yard_cms" --dbname=ch197840_yard --dbuser=ch197840_yard --dbhost=localhost --dbprefix=yard_ --prompt=dbpass <<< "$YARD_DATABASE_PASSWORD"
unset YARD_DATABASE_PASSWORD
chmod 600 "$yard_cms/wp-config.php"
wp config set DISALLOW_FILE_EDIT true --raw --path="$yard_cms"
wp config set FORCE_SSL_ADMIN true --raw --path="$yard_cms"
wp config set WP_ENVIRONMENT_TYPE staging --path="$yard_cms"
wp core install --path="$yard_cms" --url=https://ch197840.tw1.ru/cms --title='ЯРД — проекты' --admin_user=yard-owner --admin_email=yard.bureau@mail.ru --skip-email --prompt=admin_password <<< "$YARD_OWNER_PASSWORD"
unset YARD_OWNER_PASSWORD
wp option update blog_public 0 --path="$yard_cms"
wp option update timezone_string Europe/Moscow --path="$yard_cms"
wp option update default_comment_status closed --path="$yard_cms"
wp option update default_ping_status closed --path="$yard_cms"
wp plugin activate yard-projects --path="$yard_cms"
wp yard import --source="$yard_home/yard-migration-src" --path="$yard_cms"
wp post list --post_type=yard_project --fields=ID,post_title,post_status --path="$yard_cms"
wp post list --post_type=attachment --format=count --path="$yard_cms"
printf '\nCMS_INITIALIZATION_COMPLETE. Return to ChatGPT.\n'
# Public access remains denied until the subsequent verification step.
