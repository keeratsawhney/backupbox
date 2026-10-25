package com.yws.backupbox

import android.app.Activity
import android.content.ContentValues
import android.os.Environment
import android.provider.MediaStore
import android.webkit.CookieManager
import android.webkit.URLUtil
import android.widget.Toast
import java.net.HttpURLConnection
import java.net.URL
import javax.net.ssl.HttpsURLConnection

/**
 * Downloads from the box (gallery, files) into the phone's Pictures / Movies / Download folders.
 * The app downloads itself: Android's download manager does not know the box's certificate,
 * its password, nor that the box's Wi-Fi has no internet.
 */
object Downloader {
	fun download(activity: Activity, url: String, contentDisposition: String?, mimeType: String?) {
		val name	= URLUtil.guessFileName(url, contentDisposition, mimeType)
		Toast.makeText(activity, activity.getString(R.string.downloading, name), Toast.LENGTH_SHORT).show()

		Thread {
			try {
				val target	= URL(url)
				val connection	= target.openConnection() as HttpURLConnection
				if (connection is HttpsURLConnection) {
					connection.sslSocketFactory	= BoxTrust.socketFactory(activity, target.host)
					connection.hostnameVerifier	= javax.net.ssl.HostnameVerifier { host, _ -> host == target.host }
				}
				BoxTrust.basicAuth(activity, target.host)?.let { connection.setRequestProperty("Authorization", it) }
				CookieManager.getInstance().getCookie(url)?.let { connection.setRequestProperty("Cookie", it) }
				connection.connectTimeout	= 10000
				connection.readTimeout	= 60000

				if (connection.responseCode != 200) throw Exception("HTTP ${connection.responseCode}")

				val type	= (mimeType?.takeIf { it.isNotBlank() && it != "application/octet-stream" } ?: connection.contentType ?: "application/octet-stream").substringBefore(";")
				val (collection, folder)	= when {
					type.startsWith("image/") -> Pair(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, Environment.DIRECTORY_PICTURES)
					type.startsWith("video/") -> Pair(MediaStore.Video.Media.EXTERNAL_CONTENT_URI, Environment.DIRECTORY_MOVIES)
					else -> Pair(MediaStore.Downloads.EXTERNAL_CONTENT_URI, Environment.DIRECTORY_DOWNLOADS)
				}

				val values	= ContentValues().apply {
					put(MediaStore.MediaColumns.DISPLAY_NAME, name)
					put(MediaStore.MediaColumns.MIME_TYPE, type)
					put(MediaStore.MediaColumns.RELATIVE_PATH, "$folder/Backup Box")
					put(MediaStore.MediaColumns.IS_PENDING, 1)
				}
				val resolver	= activity.contentResolver
				val item	= resolver.insert(collection, values) ?: throw Exception("no storage")
				try {
					resolver.openOutputStream(item)!!.use { output -> connection.inputStream.use { it.copyTo(output, 64 * 1024) } }
					values.clear()
					values.put(MediaStore.MediaColumns.IS_PENDING, 0)
					resolver.update(item, values, null, null)
				} catch (e: Exception) {
					resolver.delete(item, null, null)
					throw e
				}

				activity.runOnUiThread {
					Toast.makeText(activity, activity.getString(R.string.downloaded, "$folder/Backup Box/$name"), Toast.LENGTH_LONG).show()
				}
			} catch (e: Exception) {
				activity.runOnUiThread {
					Toast.makeText(activity, activity.getString(R.string.download_failed, e.message ?: e.javaClass.simpleName), Toast.LENGTH_LONG).show()
				}
			}
		}.start()
	}
}
