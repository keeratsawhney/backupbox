#!/usr/bin/env bash
# Raspberry Pi 5 Active Cooler: full speed from 60 °C (the firmware's default reaches full speed at 75 °C).
# Backups keep the CPU and the NVMe SSD busy for a long time; a cool box keeps both at full speed.
# Written between markers into /boot/firmware/config.txt, so running it again replaces it. Active after a reboot.

CONFIG_TXT="/boot/firmware/config.txt"
BEGIN="# >>> Backup Box fan >>>"
END="# <<< Backup Box fan <<<"

grep -q "Raspberry Pi 5" /proc/device-tree/model 2>/dev/null || exit 0
[ -f "${CONFIG_TXT}" ] || exit 0

echo "Setting the fan to full speed from 60 °C ..."
sudo sed -i "/^${BEGIN}\$/,/^${END}\$/d" "${CONFIG_TXT}"
sudo tee -a "${CONFIG_TXT}" >/dev/null <<CONF
${BEGIN}
[all]
# fan: 45 °C slow, 50 °C, 55 °C, 60 °C and above full speed; 5 °C hysteresis
dtparam=fan_temp0=45000,fan_temp0_hyst=5000,fan_temp0_speed=100
dtparam=fan_temp1=50000,fan_temp1_hyst=5000,fan_temp1_speed=160
dtparam=fan_temp2=55000,fan_temp2_hyst=5000,fan_temp2_speed=210
dtparam=fan_temp3=60000,fan_temp3_hyst=5000,fan_temp3_speed=255
${END}
CONF
