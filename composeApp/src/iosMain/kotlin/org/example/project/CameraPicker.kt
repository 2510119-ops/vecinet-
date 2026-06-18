package org.example.project

import androidx.compose.runtime.Composable

@Composable
actual fun rememberCameraLauncher(onPhotoTaken: (String?) -> Unit): () -> Unit {
    return {}
}