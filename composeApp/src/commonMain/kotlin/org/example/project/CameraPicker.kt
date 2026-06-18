package org.example.project

import androidx.compose.runtime.Composable

@Composable
expect fun rememberCameraLauncher(onPhotoTaken: (String?) -> Unit): () -> Unit