#!/bin/sh
SCRIPT_DIR="$(cd $(dirname $0) && pwd)"

php  "$SCRIPT_DIR"/../api_tester.php -c "$SCRIPT_DIR/config.ini" -t "$SCRIPT_DIR/test.csv" -m "summary" -a "https://device.remot3.it/apv/v27.5" -u "${TESTUSERNAME}" -p "${TESTPASSWORD}" -l "$SCRIPT_DIR/auto_reg_setup" -q true

bulkid=$(grep -i '\[bulk_id\]' "$SCRIPT_DIR/auto_reg_setup.out" | awk '{ print $3 }')

echo "bulkid=$bulkid"
