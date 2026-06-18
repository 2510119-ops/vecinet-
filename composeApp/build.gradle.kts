import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins {
    alias(libs.plugins.kotlinMultiplatform)
    alias(libs.plugins.androidApplication)
    alias(libs.plugins.composeMultiplatform)
    alias(libs.plugins.composeCompiler)
    kotlin("plugin.serialization") version "2.1.0"
}

kotlin {
    androidTarget {
        compilerOptions {
            jvmTarget.set(JvmTarget.JVM_11)
        }
    }

    listOf(
        iosArm64(),
        iosSimulatorArm64()
    ).forEach { iosTarget ->
        iosTarget.binaries.framework {
            baseName = "ComposeApp"
            isStatic = true
        }
    }

    sourceSets {
        androidMain.dependencies {
            implementation("com.google.zxing:core:3.5.3")
            implementation("com.journeyapps:zxing-android-embedded:4.3.0")
            implementation("androidx.datastore:datastore-preferences:1.1.1")
            implementation("com.google.android.gms:play-services-location:21.2.0")
            implementation(libs.compose.uiToolingPreview)
            implementation(libs.androidx.activity.compose)
            implementation("io.ktor:ktor-client-android:3.4.0")
            implementation("io.coil-kt.coil3:coil-android:3.1.0")
            // Firebase — solo Android
            implementation("com.google.firebase:firebase-messaging-ktx:23.4.0")
            implementation("com.google.firebase:firebase-common-ktx:21.0.0")
            implementation("org.jetbrains.kotlinx:kotlinx-coroutines-play-services:1.7.3")
        }
        commonMain.dependencies {
            implementation("org.jetbrains.compose.material:material-icons-extended:1.6.0")
            implementation("org.jetbrains.kotlinx:kotlinx-datetime:0.6.0")
            implementation(libs.compose.runtime)
            implementation(libs.compose.foundation)
            implementation(libs.compose.material3)
            implementation(libs.compose.ui)
            implementation(libs.compose.components.resources)
            implementation(libs.compose.uiToolingPreview)
            implementation(libs.androidx.lifecycle.viewmodelCompose)
            implementation(libs.androidx.lifecycle.runtimeCompose)
            implementation("io.ktor:ktor-client-core:3.4.0")
            implementation("io.ktor:ktor-client-content-negotiation:3.4.0")
            implementation("io.ktor:ktor-serialization-kotlinx-json:3.4.0")
            implementation("io.coil-kt.coil3:coil-compose:3.1.0")
        }
        iosMain.dependencies {
            implementation("io.ktor:ktor-client-darwin:3.4.0")
        }
        commonTest.dependencies {
            implementation(libs.kotlin.test)
        }
    }
}

android {
    namespace = "org.example.project"
    compileSdk = libs.versions.android.compileSdk.get().toInt()

    defaultConfig {
        applicationId = "org.example.project"
        minSdk = libs.versions.android.minSdk.get().toInt()
        targetSdk = libs.versions.android.targetSdk.get().toInt()
        versionCode = 1
        versionName = "1.0"
    }
    packaging {
        resources {
            excludes += "/META-INF/{AL2.0,LGPL2.1}"
        }
    }
    buildTypes {
        getByName("release") {
            isMinifyEnabled = false
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11
    }
}

dependencies {
    debugImplementation(libs.compose.uiTooling)
}