package com.yws.backupbox

import android.annotation.SuppressLint
import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothGatt
import android.bluetooth.BluetoothGattCallback
import android.bluetooth.BluetoothGattCharacteristic
import android.bluetooth.BluetoothGattDescriptor
import android.bluetooth.BluetoothManager
import android.bluetooth.BluetoothProfile
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.os.Build
import android.os.Handler
import android.os.Looper
import org.json.JSONObject
import java.util.ArrayDeque
import java.util.UUID

/**
 * The box over Bluetooth Low Energy (scripts/lib_ble.py on the box): state, drive, start / stop a backup,
 * and the box's Wi-Fi details. Only small data: files always go over Wi-Fi.
 * Everything needs the pairing (code shown on the box's display).
 */
@SuppressLint("MissingPermission")
class BleBox(private val context: Context, private val listener: Listener) {
	interface Listener {
		fun onBleState(state: State)
		fun onBleStatus(status: JSONObject)
		fun onBleWifi(wifi: JSONObject)
	}

	enum class State { OFF, CONNECTING, PAIRING, CONNECTED }

	private val main	= Handler(Looper.getMainLooper())
	private val adapter	= context.getSystemService(BluetoothManager::class.java)?.adapter
	private var gatt: BluetoothGatt? = null
	private var address: String? = null
	private var wanted	= false
	private var ready	= false
	private val queue	= ArrayDeque<() -> Boolean>()
	private var busy	= false

	val available: Boolean get() = adapter?.isEnabled == true

	fun connect(deviceAddress: String) {
		if (wanted && deviceAddress == address && gatt != null) return
		disconnect()
		address	= deviceAddress
		wanted	= true
		val filter	= IntentFilter(BluetoothDevice.ACTION_BOND_STATE_CHANGED)
		if (Build.VERSION.SDK_INT >= 33) {
			context.registerReceiver(bondReceiver, filter, Context.RECEIVER_EXPORTED)
		} else {
			context.registerReceiver(bondReceiver, filter)
		}
		open()
	}

	fun disconnect() {
		if (wanted) {
			try { context.unregisterReceiver(bondReceiver) } catch (e: Exception) {}
		}
		wanted	= false
		ready	= false
		queue.clear()
		busy	= false
		gatt?.disconnect()
		gatt?.close()
		gatt	= null
		state(State.OFF)
	}

	fun command(name: String) = enqueue {
		val characteristic	= characteristic(COMMAND) ?: return@enqueue false
		val value	= JSONObject().put("c", name).toString().toByteArray()
		if (Build.VERSION.SDK_INT >= 33) {
			gatt?.writeCharacteristic(characteristic, value, BluetoothGattCharacteristic.WRITE_TYPE_DEFAULT) == 0
		} else {
			@Suppress("DEPRECATION")
			characteristic.value	= value
			@Suppress("DEPRECATION")
			gatt?.writeCharacteristic(characteristic) == true
		}
	}

	fun readWifi() = enqueue { characteristic(WIFI)?.let { gatt?.readCharacteristic(it) } == true }

	fun readStatus() = enqueue { characteristic(STATUS)?.let { gatt?.readCharacteristic(it) } == true }

	// ---------- connection ----------

	private fun open() {
		val device	= try { adapter?.getRemoteDevice(address) } catch (e: Exception) { null } ?: return
		if (adapter?.isEnabled != true) {
			state(State.OFF)
			return
		}
		state(State.CONNECTING)
		gatt	= device.connectGatt(context, false, callback, BluetoothDevice.TRANSPORT_LE)
	}

	private fun retryLater() {
		gatt?.close()
		gatt	= null
		ready	= false
		queue.clear()
		busy	= false
		if (wanted) {
			state(State.CONNECTING)
			main.postDelayed({ if (wanted && gatt == null) open() }, 4000)
		}
	}

	/** after the pairing (or when already paired): notifications on, first status */
	private fun setup() {
		ready	= true
		enqueue {
			val characteristic	= characteristic(STATUS) ?: return@enqueue false
			gatt?.setCharacteristicNotification(characteristic, true)
			val descriptor	= characteristic.getDescriptor(CCCD) ?: return@enqueue false
			if (Build.VERSION.SDK_INT >= 33) {
				gatt?.writeDescriptor(descriptor, BluetoothGattDescriptor.ENABLE_NOTIFICATION_VALUE) == 0
			} else {
				@Suppress("DEPRECATION")
				descriptor.value	= BluetoothGattDescriptor.ENABLE_NOTIFICATION_VALUE
				@Suppress("DEPRECATION")
				gatt?.writeDescriptor(descriptor) == true
			}
		}
		readStatus()
	}

	private val callback	= object : BluetoothGattCallback() {
		override fun onConnectionStateChange(g: BluetoothGatt, status: Int, newState: Int) {
			main.post {
				if (g != gatt) return@post
				if (newState == BluetoothProfile.STATE_CONNECTED) {
					g.requestMtu(517)
				} else if (newState == BluetoothProfile.STATE_DISCONNECTED) {
					retryLater()
				}
			}
		}

		override fun onMtuChanged(g: BluetoothGatt, mtu: Int, status: Int) {
			main.post { if (g == gatt) g.discoverServices() }
		}

		override fun onServicesDiscovered(g: BluetoothGatt, status: Int) {
			main.post {
				if (g != gatt) return@post
				if (g.getService(SERVICE) == null) {
					// not (yet) a Backup Box service: try again later
					g.disconnect()
					return@post
				}
				if (g.device.bondState == BluetoothDevice.BOND_BONDED) {
					state(State.CONNECTED)
					setup()
				} else {
					// pairing: Android asks for the code shown on the box's display
					state(State.PAIRING)
					g.device.createBond()
				}
			}
		}

		@Deprecated("Deprecated in Java")
		override fun onCharacteristicRead(g: BluetoothGatt, characteristic: BluetoothGattCharacteristic, status: Int) {
			if (Build.VERSION.SDK_INT >= 33) return
			@Suppress("DEPRECATION")
			read(characteristic.uuid, characteristic.value ?: ByteArray(0), status)
		}

		override fun onCharacteristicRead(g: BluetoothGatt, characteristic: BluetoothGattCharacteristic, value: ByteArray, status: Int) {
			read(characteristic.uuid, value, status)
		}

		@Deprecated("Deprecated in Java")
		override fun onCharacteristicChanged(g: BluetoothGatt, characteristic: BluetoothGattCharacteristic) {
			if (Build.VERSION.SDK_INT >= 33) return
			@Suppress("DEPRECATION")
			changed(characteristic.uuid, characteristic.value ?: ByteArray(0))
		}

		override fun onCharacteristicChanged(g: BluetoothGatt, characteristic: BluetoothGattCharacteristic, value: ByteArray) {
			changed(characteristic.uuid, value)
		}

		override fun onCharacteristicWrite(g: BluetoothGatt, characteristic: BluetoothGattCharacteristic, status: Int) {
			main.post { next() }
		}

		override fun onDescriptorWrite(g: BluetoothGatt, descriptor: BluetoothGattDescriptor, status: Int) {
			main.post { next() }
		}
	}

	private fun read(uuid: UUID, value: ByteArray, status: Int) {
		main.post {
			if (status == BluetoothGatt.GATT_SUCCESS) {
				parse(value)?.let { if (uuid == WIFI) listener.onBleWifi(it) else if (uuid == STATUS) listener.onBleStatus(it) }
			} else if (status == GATT_INSUFFICIENT_AUTHENTICATION || status == GATT_INSUFFICIENT_ENCRYPTION) {
				// not paired (any more): Android starts the pairing, setup() follows when it is done
				state(State.PAIRING)
			}
			next()
		}
	}

	private fun changed(uuid: UUID, value: ByteArray) {
		main.post { if (uuid == STATUS) parse(value)?.let { listener.onBleStatus(it) } }
	}

	private fun parse(value: ByteArray): JSONObject? = try { JSONObject(String(value)) } catch (e: Exception) { null }

	private val bondReceiver	= object : BroadcastReceiver() {
		override fun onReceive(c: Context, intent: Intent) {
			val device	= if (Build.VERSION.SDK_INT >= 33) {
				intent.getParcelableExtra(BluetoothDevice.EXTRA_DEVICE, BluetoothDevice::class.java)
			} else {
				@Suppress("DEPRECATION")
				intent.getParcelableExtra(BluetoothDevice.EXTRA_DEVICE)
			}
			if (device?.address != address) return
			when (intent.getIntExtra(BluetoothDevice.EXTRA_BOND_STATE, BluetoothDevice.ERROR)) {
				BluetoothDevice.BOND_BONDED -> if (gatt != null) { state(State.CONNECTED); setup() }
				BluetoothDevice.BOND_NONE -> if (wanted) state(State.PAIRING)
			}
		}
	}

	// ---------- one GATT operation at a time ----------

	private fun enqueue(operation: () -> Boolean) {
		if (!ready) return
		queue.add(operation)
		if (!busy) next(force = true)
	}

	private fun next(force: Boolean = false) {
		if (!force && !busy) return
		busy	= false
		while (queue.isNotEmpty()) {
			val operation	= queue.poll() ?: break
			if (operation()) {
				busy	= true
				return
			}
		}
	}

	private fun characteristic(uuid: UUID): BluetoothGattCharacteristic? = gatt?.getService(SERVICE)?.getCharacteristic(uuid)

	private fun state(state: State) = main.post { listener.onBleState(state) }

	companion object {
		val SERVICE: UUID	= UUID.fromString("7a6b0001-1b2c-4d3e-8f40-6c6262626f78")
		val STATUS: UUID	= UUID.fromString("7a6b0002-1b2c-4d3e-8f40-6c6262626f78")
		val COMMAND: UUID	= UUID.fromString("7a6b0003-1b2c-4d3e-8f40-6c6262626f78")
		val WIFI: UUID	= UUID.fromString("7a6b0004-1b2c-4d3e-8f40-6c6262626f78")
		val CCCD: UUID	= UUID.fromString("00002902-0000-1000-8000-00805f9b34fb")
		const val GATT_INSUFFICIENT_AUTHENTICATION	= 5
		const val GATT_INSUFFICIENT_ENCRYPTION	= 15
	}
}
