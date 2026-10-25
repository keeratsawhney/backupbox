package com.yws.backupbox

import android.content.Context
import android.util.Base64
import java.security.MessageDigest
import java.security.cert.CertificateException
import java.security.cert.X509Certificate
import javax.net.ssl.SSLContext
import javax.net.ssl.SSLSocketFactory
import javax.net.ssl.X509TrustManager

/**
 * The box has its own (self-signed) certificate: it is trusted the first time and pinned by its
 * SHA-256 fingerprint. A different certificate later is refused until the user trusts it again.
 * The box's password (Apache basic auth) is kept here too.
 */
object BoxTrust {
	private const val PREFS = "box"

	fun fingerprint(certificate: X509Certificate): String =
		MessageDigest.getInstance("SHA-256").digest(certificate.encoded).joinToString(":") { "%02X".format(it) }

	fun pinned(context: Context, host: String): String? =
		context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString("cert_$host", null)

	fun pin(context: Context, host: String, fingerprint: String) {
		context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString("cert_$host", fingerprint).apply()
	}

	fun credentials(context: Context, host: String): Pair<String, String>? {
		val prefs	= context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
		val user	= prefs.getString("user_$host", null) ?: return null
		val password	= prefs.getString("password_$host", null) ?: return null
		return Pair(user, password)
	}

	fun saveCredentials(context: Context, host: String, user: String, password: String) {
		context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
			.putString("user_$host", user)
			.putString("password_$host", password)
			.apply()
	}

	fun basicAuth(context: Context, host: String): String? =
		credentials(context, host)?.let { (user, password) ->
			"Basic " + Base64.encodeToString("$user:$password".toByteArray(), Base64.NO_WRAP)
		}

	/** TLS for the app's own requests (downloads): only the pinned certificate of this box */
	fun socketFactory(context: Context, host: String): SSLSocketFactory {
		val trust	= object : X509TrustManager {
			override fun checkClientTrusted(chain: Array<X509Certificate>, authType: String) {}

			override fun checkServerTrusted(chain: Array<X509Certificate>, authType: String) {
				val expected	= pinned(context, host) ?: throw CertificateException("certificate of $host not trusted yet")
				if (chain.isEmpty() || fingerprint(chain[0]) != expected) {
					throw CertificateException("certificate of $host changed")
				}
			}

			override fun getAcceptedIssuers(): Array<X509Certificate> = arrayOf()
		}
		return SSLContext.getInstance("TLS").apply { init(null, arrayOf(trust), null) }.socketFactory
	}
}
