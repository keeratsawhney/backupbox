import java.util.Properties

plugins {
	id("com.android.application")
	id("org.jetbrains.kotlin.android")
}

// release signing: the keystore from the environment (GitHub secrets: see .github/workflows/android.yml)
// or android/keystore/ (local, never committed); without one the build is signed with the debug key
val keystorePath = System.getenv("BACKUPBOX_KEYSTORE") ?: "${rootDir}/keystore/backupbox.keystore"
val keystoreProps = Properties().apply {
	val file = file("${rootDir}/keystore/keystore.properties")
	if (file.exists()) file.inputStream().use { load(it) }
}
val hasReleaseKey = file(keystorePath).exists()

android {
	namespace = "com.yws.backupbox"
	compileSdk = 35

	defaultConfig {
		applicationId = "com.yws.backupbox"
		minSdk = 29
		targetSdk = 35
		versionCode = (System.getenv("GITHUB_RUN_NUMBER") ?: "1").toInt()
		versionName = "1.0." + (System.getenv("GITHUB_RUN_NUMBER") ?: "0")
	}

	signingConfigs {
		create("release") {
			storeFile = file(keystorePath)
			storePassword = System.getenv("BACKUPBOX_KEYSTORE_PASSWORD") ?: keystoreProps.getProperty("storePassword")
			keyAlias = System.getenv("BACKUPBOX_KEY_ALIAS") ?: keystoreProps.getProperty("keyAlias")
			keyPassword = System.getenv("BACKUPBOX_KEY_PASSWORD") ?: keystoreProps.getProperty("keyPassword")
		}
	}

	buildTypes {
		release {
			isMinifyEnabled = false
			signingConfig = signingConfigs.getByName(if (hasReleaseKey) "release" else "debug")
		}
	}

	compileOptions {
		sourceCompatibility = JavaVersion.VERSION_17
		targetCompatibility = JavaVersion.VERSION_17
	}

	kotlinOptions {
		jvmTarget = "17"
	}
}
