package org.example.project

import androidx.compose.runtime.Composable

@Composable
actual fun rememberLocationPermissionLauncher(onResult: (Boolean) -> Unit): () -> Unit {
    return {}
}