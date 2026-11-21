package com.yws.backupbox

import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.net.wifi.WifiManager
import android.provider.Settings

/**
 * Wi-Fi on or off. Since Android 10 an app can not switch Wi-Fi on itself (WifiManager.setWifiEnabled does
 * nothing for apps): Android's own panel does it with one tap, on top of the app.
 */
object Wifi {
	fun isOn(context: Context): Boolean =
		context.applicationContext.getSystemService(WifiManager::class.java)?.isWifiEnabled == true

	fun openPanel(activity: Activity) {
		try {
			activity.startActivity(Intent(Settings.Panel.ACTION_INTERNET_CONNECTIVITY))
		} catch (e: ActivityNotFoundException) {
			try {
				activity.startActivity(Intent(Settings.ACTION_WIFI_SETTINGS))
			} catch (e2: ActivityNotFoundException) {
				// no settings app to open
			}
		}
	}
}
