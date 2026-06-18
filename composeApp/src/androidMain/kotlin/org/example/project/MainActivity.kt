package org.example.project

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.*
import androidx.compose.ui.tooling.preview.Preview
import androidx.core.content.ContextCompat
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.tasks.await

class MainActivity : ComponentActivity() {

    companion object {
        var instance: MainActivity? = null
    }

    private val permisos = mutableListOf(
        Manifest.permission.CAMERA,
        Manifest.permission.ACCESS_FINE_LOCATION,
        Manifest.permission.ACCESS_COARSE_LOCATION
    ).apply {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            add(Manifest.permission.POST_NOTIFICATIONS)
        }
    }.toTypedArray()

    private val requestPermissionsLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        instance = this
        AppContext.init(applicationContext)

        // Inicializar Firebase manualmente (sin plugin google-services)
        FirebaseApp.initializeApp(this)

        val faltanPermisos = permisos.any {
            ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED
        }
        if (faltanPermisos) {
            requestPermissionsLauncher.launch(permisos)
        }

        setContent {
            var token by remember { mutableStateOf<String?>(null) }

            LaunchedEffect(Unit) {
                try {
                    token = FirebaseMessaging.getInstance().token.await()
                } catch (e: Exception) { }
            }

            App(fcmToken = token)
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        instance = null
    }
}

@Preview
@Composable
fun AppAndroidPreview() {
    App()
}