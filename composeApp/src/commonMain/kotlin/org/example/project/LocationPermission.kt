package org.example.project

import androidx.compose.runtime.Composable

@Composable
expect fun rememberLocationPermissionLauncher(onResult: (Boolean) -> Unit): () -> Unit