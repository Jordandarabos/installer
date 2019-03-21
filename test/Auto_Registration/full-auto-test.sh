#!/bin/sh
# adding integration with Doug's php API tester code

SCRIPT_DIR="$(cd $(dirname $0) && pwd)"

countServices()
{
    echo
    sudo connectd_control status all
    nrunning=$(sudo connectd_control status all | grep -c "and running")
    echo "$nrunning are running"
    nstopped=$(sudo connectd_control status all | grep -c stopped)
    echo "$nstopped are stopped"
    nenabled=$(sudo connectd_control status all | grep -c enabled)
    echo "$nenabled are enabled"
    ndisabled=$(sudo connectd_control status all | grep -c disabled)
    echo "$ndisabled are disabled"
    echo
}

echo "Creating new product definition and auto-registration..."
bulk_id=$("$SCRIPT_DIR"/auto_registration_with_device/create_product_and_auto_reg.sh | grep "BulkId" | awk -F"=" '{ print $2 }')
echo "bulk_id=$bulk_id"

# need to set Bulk Identification Code here
sudo bash -c "echo $bulk_id > /etc/connectd/bulk_identification_code.txt"

sudo "$SCRIPT_DIR"/auto-reg-test.sh
sudo connectd_control start all
echo
echo "You should now have some services running."
echo "Check the counts below."
countServices

echo "[PLACE HOLDER] Now add a new Service and enable it."

sudo connectd_control -v dprovision
sudo connectd_control -v bprovision all

echo
echo "[PLACE HOLDER] You should now have 1 more service running."
countServices

echo
echo "[PLACE HOLDER] Now disable a Service."

sudo connectd_control -v dprovision
sudo connectd_control -v bprovision all

echo
echo "[PLACE HOLDER] You should now have 1 fewer services running."
countServices

echo
echo "[PLACE HOLDER] Now re-enable the Service."

sudo connectd_control -v dprovision
sudo connectd_control -v bprovision all

echo
echo "[PLACE HOLDER] You should now have 1 more service running."
countServices

"$SCRIPT_DIR"/auto_registration_with_device/cleanup_product_and_auto_reg.sh
