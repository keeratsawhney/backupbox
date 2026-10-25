package com.yws.backupbox

import android.net.ConnectivityManager
import android.net.Network
import java.net.HttpURLConnection
import java.net.Inet4Address
import java.net.URL
import java.util.concurrent.Executors
import java.util.concurrent.RejectedExecutionException
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicInteger

/** A Backup Box on the network; locked: it asks for a password. */
data class Box(val address: String, val locked: Boolean)

/**
 * Finds boxes: the last used address and the hotspot address (10.41.0.1) first,
 * then every address of the Wi-Fi's /24 network. A box answers on port 8080 with its
 * touch API (or a password prompt from Apache).
 */
class BoxFinder(private val connectivity: ConnectivityManager) {
	// a new search replaces a running one (e.g. when the Wi-Fi comes up after the app started)
	private val generation	= AtomicInteger(0)
	private var pool	= Executors.newFixedThreadPool(48)

	@Synchronized
	fun search(first: List<String>, wifi: Network?, found: (Box) -> Unit, done: () -> Unit) {
		val search	= generation.incrementAndGet()
		pool.shutdownNow()
		pool	= Executors.newFixedThreadPool(48)

		val addresses	= LinkedHashSet<String>()
		addresses.addAll(first.filter { it.isNotBlank() })
		addresses.add(HOTSPOT)
		addresses.addAll(subnet(wifi))

		val current	= pool
		Thread {
			try {
				addresses.forEach { address ->
					current.execute { probe(address)?.let { if (generation.get() == search) found(it) } }
				}
			} catch (e: RejectedExecutionException) {
				// replaced by a newer search
			}
			current.shutdown()
			current.awaitTermination(20, TimeUnit.SECONDS)
			if (generation.get() == search) done()
		}.start()
	}

	@Synchronized
	fun stop() {
		generation.incrementAndGet()
		pool.shutdownNow()
	}

	private fun subnet(wifi: Network?): List<String> {
		val link	= wifi?.let { connectivity.getLinkProperties(it) } ?: return emptyList()
		val own	= link.linkAddresses.map { it.address }.filterIsInstance<Inet4Address>().firstOrNull() ?: return emptyList()
		val parts	= own.hostAddress?.split(".") ?: return emptyList()
		if (parts.size != 4) return emptyList()
		val base	= parts.take(3).joinToString(".")
		return (1..254).map { "$base.$it" }.filter { it != own.hostAddress }
	}

	companion object {
		const val HOTSPOT	= "10.41.0.1"

		/** Is a Backup Box at this address? (plain http on port 8080, short timeouts) */
		fun probe(address: String): Box? {
			return try {
				val connection	= URL("http://$address:8080/touch-api.php?action=status").openConnection() as HttpURLConnection
				connection.connectTimeout	= 800
				connection.readTimeout	= 2500
				connection.instanceFollowRedirects	= false
				connection.setRequestProperty("Cache-Control", "no-store")
				val code	= connection.responseCode
				val box	= when {
					code == 200 && connection.inputStream.bufferedReader().use { it.readText() }.contains("\"ok\"") -> Box(address, false)
					// password protected box: Apache asks for it
					code == 401 && (connection.getHeaderField("WWW-Authenticate") ?: "").contains("Basic") -> Box(address, true)
					else -> null
				}
				connection.disconnect()
				box
			} catch (e: Exception) {
				null
			}
		}
	}
}
