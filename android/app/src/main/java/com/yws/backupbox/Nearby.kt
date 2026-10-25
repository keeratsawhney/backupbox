package com.yws.backupbox

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.app.AlertDialog
import android.bluetooth.BluetoothDevice
import android.bluetooth.le.ScanFilter
import android.bluetooth.le.ScanResult
import android.companion.AssociationInfo
import android.companion.AssociationRequest
import android.companion.BluetoothLeDeviceFilter
import android.companion.CompanionDeviceManager
import android.content.Context
import android.content.Intent
import android.content.IntentSender
import android.content.pm.PackageManager
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.net.wifi.WifiNetworkSpecifier
import android.os.Build
import android.os.ParcelUuid
import android.view.View
import android.widget.Button
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import org.json.JSONObject

/**
 * The paired box nearby: pairing through Android's own device sheet (like headphones),
 * its state over Bluetooth, start / stop a backup, and "Open box": the app joins the box's Wi-Fi
 * by itself (or finds it in the network the phone is on) and shows the touch UI there.
 * Files always go over Wi-Fi.
 */
class Nearby(
	private val activity: Activity,
	private val connectivity: ConnectivityManager,
	private val openBox: (Box) -> Unit,
	private val pinNetwork: (Network?) -> Unit,
) : BleBox.Listener {
	private val prefs	= activity.getSharedPreferences("app", Context.MODE_PRIVATE)
	private val ble	= BleBox(activity, this)

	private val card: LinearLayout	= activity.findViewById(R.id.bleCard)
	private val stateView: TextView	= activity.findViewById(R.id.bleState)
	private val linesView: TextView	= activity.findViewById(R.id.bleLines)
	private val driveText: TextView	= activity.findViewById(R.id.bleDriveText)
	private val driveBar: ProgressBar	= activity.findViewById(R.id.bleDrive)
	private val openButton: Button	= activity.findViewById(R.id.bleOpen)
	private val startButton: Button	= activity.findViewById(R.id.bleStart)
	private val powerButton: Button	= activity.findViewById(R.id.blePower)
	private val pairButton: Button	= activity.findViewById(R.id.pair)

	private var running	= false
	private var lastMessage	= ""
	private var opening	= false
	private var hotspotCallback: ConnectivityManager.NetworkCallback? = null
	private var pending: (() -> Unit)? = null

	private val address: String? get() = prefs.getString("ble", null)

	init {
		pairButton.setOnClickListener { withPermission { pair() } }
		openButton.setOnClickListener { opening = true; ble.readWifi() }
		startButton.setOnClickListener {
			confirm(if (running) R.string.ble_stop_confirm else R.string.ble_start_confirm) {
				ble.command(if (running) "stop" else "start")
			}
		}
		powerButton.setOnClickListener { confirm(R.string.ble_power_off_confirm) { ble.command("poweroff") } }
		showCard(false)
	}

	/** the connect screen is shown: talk to the paired box */
	fun resume() {
		val address	= address
		pairButton.setText(if (address == null) R.string.ble_pair else R.string.ble_pair_other)
		if (address != null && hasPermission()) {
			showCard(true)
			ble.connect(address)
		}
	}

	/** the touch UI is shown (over Wi-Fi): Bluetooth rests */
	fun pause() {
		ble.disconnect()
	}

	fun leaveBox() {
		// the box's Wi-Fi joined by the app is released with the box
		hotspotCallback?.let { try { connectivity.unregisterNetworkCallback(it) } catch (e: Exception) {} }
		hotspotCallback	= null
		pinNetwork(null)
	}

	// ---------- pairing ----------

	@SuppressLint("MissingPermission")
	private fun pair() {
		val manager	= activity.getSystemService(CompanionDeviceManager::class.java) ?: return
		val filter	= BluetoothLeDeviceFilter.Builder()
			.setScanFilter(ScanFilter.Builder().setServiceUuid(ParcelUuid(BleBox.SERVICE)).build())
			.build()
		val request	= AssociationRequest.Builder().addDeviceFilter(filter).setSingleDevice(false).build()

		val launch	= { sender: IntentSender ->
			try {
				activity.startIntentSenderForResult(sender, REQUEST_PAIR, null, 0, 0, 0)
			} catch (e: Exception) {
				toast(e.message ?: "")
			}
		}

		if (Build.VERSION.SDK_INT >= 33) {
			manager.associate(request, activity.mainExecutor, object : CompanionDeviceManager.Callback() {
				override fun onAssociationPending(intentSender: IntentSender) = launch(intentSender)
				override fun onAssociationCreated(associationInfo: AssociationInfo) {}
				override fun onFailure(error: CharSequence?) = toast(activity.getString(R.string.ble_pair_failed))
			})
		} else {
			@Suppress("DEPRECATION")
			manager.associate(request, object : CompanionDeviceManager.Callback() {
				@Deprecated("Deprecated in Java")
				override fun onDeviceFound(intentSender: IntentSender) = launch(intentSender)
				override fun onFailure(error: CharSequence?) = toast(activity.getString(R.string.ble_pair_failed))
			}, null)
		}
	}

	/** from MainActivity.onActivityResult: the box chosen in Android's sheet */
	fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?): Boolean {
		if (requestCode != REQUEST_PAIR) return false
		if (resultCode != Activity.RESULT_OK || data == null) return true

		var device: String? = null
		if (Build.VERSION.SDK_INT >= 33) {
			device	= data.getParcelableExtra(CompanionDeviceManager.EXTRA_ASSOCIATION, AssociationInfo::class.java)?.deviceMacAddress?.toString()?.uppercase()
		}
		if (device == null) {
			@Suppress("DEPRECATION")
			val result: Any? = data.getParcelableExtra(CompanionDeviceManager.EXTRA_DEVICE)
			device	= when (result) {
				is ScanResult -> result.device.address
				is BluetoothDevice -> result.address
				else -> null
			}
		}
		if (device != null) {
			prefs.edit().putString("ble", device).apply()
			resume()
		}
		return true
	}

	// ---------- permission (Android 12+: "nearby devices") ----------

	private fun hasPermission(): Boolean =
		Build.VERSION.SDK_INT < 31 || activity.checkSelfPermission(Manifest.permission.BLUETOOTH_CONNECT) == PackageManager.PERMISSION_GRANTED

	private fun withPermission(action: () -> Unit) {
		if (hasPermission()) {
			action()
			return
		}
		pending	= action
		activity.requestPermissions(arrayOf(Manifest.permission.BLUETOOTH_CONNECT), REQUEST_PERMISSION)
	}

	fun onRequestPermissionsResult(requestCode: Int, grantResults: IntArray): Boolean {
		if (requestCode != REQUEST_PERMISSION) return false
		if (grantResults.firstOrNull() == PackageManager.PERMISSION_GRANTED) {
			pending?.invoke()
		} else {
			toast(activity.getString(R.string.ble_permission))
		}
		pending	= null
		return true
	}

	// ---------- Bluetooth ----------

	override fun onBleState(state: BleBox.State) {
		when (state) {
			BleBox.State.OFF -> setState(activity.getString(R.string.ble_off), false)
			BleBox.State.CONNECTING -> setState(activity.getString(R.string.ble_connecting), false)
			BleBox.State.PAIRING -> setState(activity.getString(R.string.ble_pairing), false)
			BleBox.State.CONNECTED -> {}
		}
	}

	override fun onBleStatus(status: JSONObject) {
		showCard(true)
		running	= status.optString("s") == "running"
		setState(activity.getString(if (running) R.string.ble_running else R.string.ble_ready), true)
		startButton.setText(if (running) R.string.ble_stop else R.string.ble_start)

		// what the box's display shows (without its title)
		val lines	= status.optJSONArray("l")
		val shown	= mutableListOf<String>()
		for (i in 0 until (lines?.length() ?: 0)) {
			val line	= lines!!.optString(i)
			when {
				line.startsWith("PGBAR=") -> shown.add("${line.substring(6).toDoubleOrNull()?.toInt() ?: 0} %")
				line.isNotBlank() && line != "BACKUP BOX" && line != stateView.text -> shown.add(line)
			}
		}
		linesView.text	= shown.joinToString("  ·  ")
		linesView.visibility	= if (shown.isEmpty()) View.GONE else View.VISIBLE

		val drive	= status.optJSONObject("d")
		if (drive != null) {
			val used	= if (drive.isNull("u")) null else drive.optDouble("u")
			driveText.text	= activity.getString(R.string.ble_drive, drive.optString("n"),
				if (used != null) "${(used * 100).toInt()} % · ${drive.optString("z")}" else drive.optString("z"))
			driveBar.progress	= ((used ?: 0.0) * 100).toInt()
			driveBar.visibility	= if (used != null) View.VISIBLE else View.GONE
			driveText.visibility	= View.VISIBLE
		} else {
			driveText.visibility	= View.GONE
			driveBar.visibility	= View.GONE
		}

		val message	= status.optString("m")
		if (message.isNotEmpty() && message != lastMessage) {
			when (message) {
				"started" -> toast(activity.getString(R.string.msg_started))
				"stopped" -> toast(activity.getString(R.string.msg_stopped))
				"no_default" -> toast(activity.getString(R.string.msg_no_default))
				"running" -> toast(activity.getString(R.string.msg_running))
			}
		}
		lastMessage	= message
	}

	override fun onBleWifi(wifi: JSONObject) {
		if (!opening) return
		opening	= false

		// 1. the box in the network the phone is on
		val lan	= wifi.optJSONArray("lan")
		val addresses	= (0 until (lan?.length() ?: 0)).map { lan!!.optString(it) }.filter { it.isNotBlank() }
		toast(activity.getString(R.string.ble_joining))
		Thread {
			val reachable	= addresses.firstNotNullOfOrNull { BoxFinder.probe(it) }
				?: BoxFinder.probe(BoxFinder.HOTSPOT)
			activity.runOnUiThread {
				when {
					reachable != null -> openBox(reachable)
					// 2. the box's own hotspot: the app joins it (Android asks once), the phone keeps its internet
					wifi.optString("ssid").isNotEmpty() -> joinHotspot(wifi.optString("ssid"), wifi.optString("pw"), wifi.optString("ip", BoxFinder.HOTSPOT))
					else -> toast(activity.getString(R.string.ble_no_wifi))
				}
			}
		}.start()
	}

	private fun joinHotspot(ssid: String, password: String, ip: String) {
		leaveBox()
		val specifier	= WifiNetworkSpecifier.Builder().setSsid(ssid).apply {
			if (password.isNotEmpty()) setWpa2Passphrase(password)
		}.build()
		val request	= NetworkRequest.Builder()
			.addTransportType(NetworkCapabilities.TRANSPORT_WIFI)
			.removeCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
			.setNetworkSpecifier(specifier)
			.build()

		val callback	= object : ConnectivityManager.NetworkCallback() {
			override fun onAvailable(network: Network) {
				pinNetwork(network)
				activity.runOnUiThread { openBox(Box(ip, false)) }
			}

			override fun onUnavailable() {
				activity.runOnUiThread { toast(activity.getString(R.string.ble_join_failed)) }
			}

			override fun onLost(network: Network) {
				pinNetwork(null)
			}
		}
		hotspotCallback	= callback
		connectivity.requestNetwork(request, callback, 60000)
	}

	// ---------- view ----------

	private fun showCard(show: Boolean) {
		card.visibility	= if (show) View.VISIBLE else View.GONE
	}

	private fun setState(text: String, connected: Boolean) {
		if (address != null) showCard(true)
		stateView.text	= text
		openButton.isEnabled	= connected
		startButton.isEnabled	= connected
		powerButton.isEnabled	= connected
		openButton.alpha	= if (connected) 1f else 0.5f
		startButton.alpha	= if (connected) 1f else 0.5f
		powerButton.alpha	= if (connected) 1f else 0.5f
		if (!connected) {
			linesView.visibility	= View.GONE
		}
	}

	private fun confirm(message: Int, action: () -> Unit) {
		AlertDialog.Builder(activity)
			.setMessage(message)
			.setPositiveButton(R.string.ok) { _, _ -> action() }
			.setNegativeButton(R.string.cancel, null)
			.show()
	}

	private fun toast(text: String) = Toast.makeText(activity, text, Toast.LENGTH_LONG).show()

	companion object {
		const val REQUEST_PAIR	= 41
		const val REQUEST_PERMISSION	= 42
	}
}
