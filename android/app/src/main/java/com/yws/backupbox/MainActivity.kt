package com.yws.backupbox

import android.annotation.SuppressLint
import android.app.Activity
import android.app.AlertDialog
import android.content.Context
import android.content.Intent
import android.graphics.Typeface
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.net.Uri
import android.net.http.SslError
import android.os.Bundle
import android.text.InputType
import android.view.View
import android.view.inputmethod.EditorInfo
import android.webkit.HttpAuthHandler
import android.webkit.SslErrorHandler
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.ScrollView
import android.widget.TextView

/**
 * Backup Box for Android: finds the box and shows its touch UI (touch.php).
 *
 * - The app keeps to the Wi-Fi: the box's hotspot has no internet, Android would send everything
 *   over mobile data otherwise.
 * - https with the box's own certificate (trusted the first time, pinned), its password is remembered.
 * - Downloads go into the phone's Pictures / Movies / Download folders.
 */
class MainActivity : Activity() {
	private lateinit var web: WebView
	private lateinit var connectView: ScrollView
	private lateinit var status: TextView
	private lateinit var progress: ProgressBar
	private lateinit var boxesView: LinearLayout
	private lateinit var addressField: EditText

	private lateinit var connectivity: ConnectivityManager
	private lateinit var finder: BoxFinder
	private var wifi: Network? = null
	private var pinned: Network? = null	// the box's own Wi-Fi joined by the app (Nearby)
	private lateinit var nearby: Nearby
	private var currentBox: Box? = null
	private var autoOpen = true
	private val found = mutableListOf<Box>()
	private val authTried = mutableSetOf<String>()

	private val prefs by lazy { getSharedPreferences("app", Context.MODE_PRIVATE) }

	override fun onCreate(savedInstanceState: Bundle?) {
		super.onCreate(savedInstanceState)
		setContentView(R.layout.activity_main)

		web	= findViewById(R.id.web)
		connectView	= findViewById(R.id.connect)
		status	= findViewById(R.id.status)
		progress	= findViewById(R.id.progress)
		boxesView	= findViewById(R.id.boxes)
		addressField	= findViewById(R.id.address)

		connectivity	= getSystemService(ConnectivityManager::class.java)
		finder	= BoxFinder(connectivity)

		setupWeb()
		keepToWifi()
		nearby	= Nearby(this, connectivity, ::openBox, ::pin)

		findViewById<Button>(R.id.search).setOnClickListener { search() }
		findViewById<Button>(R.id.go).setOnClickListener { connectTyped() }
		addressField.setOnEditorActionListener { _, action, _ ->
			if (action == EditorInfo.IME_ACTION_GO) { connectTyped(); true } else false
		}
		addressField.setText(prefs.getString("last", ""))

		if (!openLink(intent)) {
			nearby.resume()
			search()
		}
	}

	@Deprecated("Deprecated in Java")
	override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
		if (!nearby.onActivityResult(requestCode, resultCode, data)) {
			@Suppress("DEPRECATION")
			super.onActivityResult(requestCode, resultCode, data)
		}
	}

	override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
		if (!nearby.onRequestPermissionsResult(requestCode, grantResults)) {
			super.onRequestPermissionsResult(requestCode, permissions, grantResults)
		}
	}

	/** the app's requests go to the box's own Wi-Fi (joined by Nearby), else to the Wi-Fi the phone is on */
	private fun pin(network: Network?) {
		pinned	= network
		connectivity.bindProcessToNetwork(network ?: wifi)
	}

	override fun onNewIntent(intent: Intent) {
		super.onNewIntent(intent)
		openLink(intent)
	}

	/** backupbox://<address>: opened from the box's page in a browser (its QR code, a link) */
	private fun openLink(intent: Intent?): Boolean {
		val data	= intent?.data ?: return false
		if (data.scheme != "backupbox") return false
		val address	= data.host?.takeIf { it.matches(Regex("^[\\w.-]+$")) } ?: return false
		openBox(Box(address, false))
		return true
	}

	// ---------- network ----------

	/** Use the Wi-Fi for everything the app does, also when it has no internet (the box's hotspot). */
	private fun keepToWifi() {
		val request	= NetworkRequest.Builder()
			.addTransportType(NetworkCapabilities.TRANSPORT_WIFI)
			.removeCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
			.build()

		connectivity.registerNetworkCallback(request, object : ConnectivityManager.NetworkCallback() {
			override fun onAvailable(network: Network) {
				wifi	= network
				if (pinned == null) connectivity.bindProcessToNetwork(network)
				// search again, now with the Wi-Fi's own network
				runOnUiThread { if (connectView.visibility == View.VISIBLE && currentBox == null) search() }
			}

			override fun onLost(network: Network) {
				if (wifi == network) {
					wifi	= null
					if (pinned == null) connectivity.bindProcessToNetwork(null)
				}
			}
		})
	}

	// ---------- finding the box ----------

	private fun search() {
		found.clear()
		boxesView.removeAllViews()
		progress.visibility	= View.VISIBLE
		status.setText(R.string.searching)

		val last	= prefs.getString("last", "") ?: ""
		finder.search(listOf(last), wifi,
			found = { box ->
				runOnUiThread {
					if (found.none { it.address == box.address }) {
						found.add(box)
						addBoxRow(box, last)
						// the box used last time: open it straight away
						if (autoOpen && box.address == last && connectView.visibility == View.VISIBLE) openBox(box)
					}
				}
			},
			done = {
				runOnUiThread {
					progress.visibility	= View.GONE
					status.setText(if (found.isEmpty()) R.string.found_none else R.string.found_some)
				}
			})
	}

	private fun addBoxRow(box: Box, last: String) {
		val row	= LinearLayout(this).apply {
			orientation	= LinearLayout.VERTICAL
			setBackgroundResource(R.drawable.card)
			setPadding(dp(16), dp(12), dp(16), dp(12))
			isClickable	= true
			isFocusable	= true
			setOnClickListener { openBox(box) }
		}
		row.addView(TextView(this).apply {
			text	= box.address
			textSize	= 18f
			setTypeface(typeface, Typeface.BOLD)
			setTextColor(getColor(R.color.ink))
		})
		val labels	= mutableListOf(getString(if (box.locked) R.string.box_password else R.string.box_found))
		if (box.address == last) labels.add(getString(R.string.last_box))
		if (box.address == BoxFinder.HOTSPOT) labels.add(getString(R.string.hotspot_box))
		row.addView(TextView(this).apply {
			text	= labels.joinToString("  ·  ")
			textSize	= 13f
			setTextColor(getColor(R.color.muted))
		})
		boxesView.addView(row, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply {
			bottomMargin	= dp(8)
		})
	}

	private fun connectTyped() {
		val address	= addressField.text.toString().trim()
			.removePrefix("https://").removePrefix("http://").substringBefore("/").substringBefore(":")
		if (address.isEmpty()) return
		openBox(Box(address, false))
	}

	// ---------- the box's touch UI ----------

	@SuppressLint("SetJavaScriptEnabled")
	private fun setupWeb() {
		web.settings.apply {
			javaScriptEnabled	= true
			domStorageEnabled	= true
			mediaPlaybackRequiresUserGesture	= false
			cacheMode	= WebSettings.LOAD_DEFAULT
			userAgentString	= "$userAgentString BackupBoxApp/1"
			setSupportZoom(false)
		}
		web.webChromeClient	= WebChromeClient()
		web.webViewClient	= BoxClient()
		web.setDownloadListener { url, _, contentDisposition, mimeType, _ ->
			Downloader.download(this, url, contentDisposition, mimeType)
		}
	}

	private fun openBox(box: Box) {
		finder.stop()
		nearby.pause()
		currentBox	= box
		autoOpen	= false
		authTried.clear()
		prefs.edit().putString("last", box.address).apply()
		addressField.setText(box.address)

		connectView.visibility	= View.GONE
		web.visibility	= View.VISIBLE
		clearHistoryWhenLoaded	= true
		fallback	= true
		web.loadUrl("https://${box.address}/touch.php")
	}

	// https did not answer: plain http on port 8080, the address the box search found the box on
	private var fallback = false

	// the blank page shown while choosing a box must not stay in the history (back would show it)
	private var clearHistoryWhenLoaded = false

	private fun showConnect(message: String? = null) {
		nearby.leaveBox()
		nearby.resume()
		web.stopLoading()
		web.loadUrl("about:blank")
		web.visibility	= View.GONE
		connectView.visibility	= View.VISIBLE
		currentBox	= null
		if (message != null) {
			boxesView.removeAllViews()
			found.clear()
			progress.visibility	= View.GONE
			status.text	= message
		} else {
			search()
		}
	}

	private inner class BoxClient : WebViewClient() {
		override fun onPageFinished(view: WebView, url: String) {
			if (clearHistoryWhenLoaded && url.startsWith("http")) {
				clearHistoryWhenLoaded	= false
				view.clearHistory()
			}
		}

		override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
			val host	= request.url.host
			if (host == currentBox?.address || request.url.scheme == "about") return false
			// links to other sites: the phone's browser
			startActivity(Intent(Intent.ACTION_VIEW, request.url))
			return true
		}

		@SuppressLint("WebViewClientOnReceivedSslError")
		override fun onReceivedSslError(view: WebView, handler: SslErrorHandler, error: SslError) {
			val host	= Uri.parse(error.url).host ?: ""
			val certificate	= error.certificate.x509Certificate
			// a refused certificate never falls back to plain http
			fallback	= false
			if (certificate == null || host != currentBox?.address) {
				handler.cancel()
				return
			}
			val fingerprint	= BoxTrust.fingerprint(certificate)
			when (BoxTrust.pinned(this@MainActivity, host)) {
				null -> {
					// first visit: trust this box's own certificate from now on
					BoxTrust.pin(this@MainActivity, host, fingerprint)
					fallback	= true
					handler.proceed()
				}
				fingerprint -> { fallback = true; handler.proceed() }
				else -> {
					handler.cancel()
					AlertDialog.Builder(this@MainActivity)
						.setTitle(R.string.cert_changed_title)
						.setMessage(getString(R.string.cert_changed_text, host))
						.setPositiveButton(R.string.cert_trust) { _, _ ->
							BoxTrust.pin(this@MainActivity, host, fingerprint)
							view.reload()
						}
						.setNegativeButton(R.string.choose_box) { _, _ -> showConnect() }
						.setCancelable(false)
						.show()
				}
			}
		}

		override fun onReceivedHttpAuthRequest(view: WebView, handler: HttpAuthHandler, host: String, realm: String) {
			val saved	= BoxTrust.credentials(this@MainActivity, host)
			if (saved != null && authTried.add(host)) {
				handler.proceed(saved.first, saved.second)
				return
			}
			askPassword(host, saved?.first, handler)
		}

		override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
			if (request.isForMainFrame && web.visibility == View.VISIBLE) {
				val box	= currentBox
				if (fallback && box != null && request.url.scheme == "https") {
					fallback	= false
					view.loadUrl("http://${box.address}:8080/touch.php")
					return
				}
				showConnect(getString(R.string.load_failed, error.description))
				addRetry()
			}
		}
	}

	private fun addRetry() {
		val box	= Box(prefs.getString("last", "") ?: "", false)
		if (box.address.isEmpty()) return
		boxesView.addView(Button(this).apply {
			text	= getString(R.string.retry) + "  " + box.address
			isAllCaps	= false
			setTextColor(getColor(R.color.paper))
			setBackgroundResource(R.drawable.button)
			setOnClickListener { openBox(box) }
		}, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, dp(52)))
	}

	private fun askPassword(host: String, user: String?, handler: HttpAuthHandler) {
		val form	= LinearLayout(this).apply {
			orientation	= LinearLayout.VERTICAL
			setPadding(dp(20), dp(8), dp(20), 0)
		}
		val userField	= EditText(this).apply {
			hint	= getString(R.string.login_user)
			setText(user ?: "")
			inputType	= InputType.TYPE_CLASS_TEXT
			isSingleLine	= true
		}
		val passwordField	= EditText(this).apply {
			hint	= getString(R.string.login_password)
			inputType	= InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
			isSingleLine	= true
		}
		form.addView(userField)
		form.addView(passwordField)

		AlertDialog.Builder(this)
			.setTitle(R.string.login_title)
			.setView(form)
			.setPositiveButton(R.string.login_ok) { _, _ ->
				val name	= userField.text.toString().trim()
				val password	= passwordField.text.toString()
				BoxTrust.saveCredentials(this, host, name, password)
				handler.proceed(name, password)
			}
			.setNegativeButton(R.string.cancel) { _, _ ->
				handler.cancel()
				showConnect()
			}
			.setCancelable(false)
			.show()
	}

	// ---------- back button ----------

	@Deprecated("Deprecated in Java")
	override fun onBackPressed() {
		if (web.visibility == View.VISIBLE) {
			// the touch UI keeps its tabs and panels in the history: back closes them first
			if (web.canGoBack()) {
				web.goBack()
				return
			}
			AlertDialog.Builder(this)
				.setTitle(R.string.leave_title)
				.setMessage(R.string.leave_text)
				.setPositiveButton(R.string.leave) { _, _ -> showConnect() }
				.setNegativeButton(R.string.cancel, null)
				.show()
			return
		}
		@Suppress("DEPRECATION")
		super.onBackPressed()
	}

	override fun onDestroy() {
		finder.stop()
		nearby.pause()
		nearby.leaveBox()
		connectivity.bindProcessToNetwork(null)
		web.destroy()
		super.onDestroy()
	}

	private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()
}
