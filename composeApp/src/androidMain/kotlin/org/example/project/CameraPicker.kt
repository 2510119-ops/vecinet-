package org.example.project

import android.graphics.Bitmap
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.Composable
import androidx.compose.ui.platform.LocalContext
import androidx.core.net.toUri
import java.io.File
import java.io.FileOutputStream

@Composable
actual fun rememberCameraLauncher(onPhotoTaken: (String?) -> Unit): () -> Unit {
    val context = LocalContext.current
    val launcher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.TakePicturePreview()
    ) { bitmap ->
        if (bitmap != null) {
            try {
                val file = File(context.cacheDir, "foto_${System.currentTimeMillis()}.jpg")
                FileOutputStream(file).use { bitmap.compress(Bitmap.CompressFormat.JPEG, 90, it) }
                onPhotoTaken(file.toUri().toString())
            } catch (e: Exception) {
                onPhotoTaken(null)
            }
        } else {
            onPhotoTaken(null)
        }
    }
    return {
        try {
            launcher.launch(null)
        } catch (e: Exception) {
            onPhotoTaken(null)
        }
    }
}