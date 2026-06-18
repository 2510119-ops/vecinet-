package org.example.project

import androidx.compose.runtime.Composable

@Composable
actual fun rememberImagePickerLauncher(onImageSelected: (String?) -> Unit): () -> Unit {
    return {}
}