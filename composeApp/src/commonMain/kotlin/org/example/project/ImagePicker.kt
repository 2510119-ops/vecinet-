
package org.example.project

import androidx.compose.runtime.Composable

@Composable
expect fun rememberImagePickerLauncher(onImageSelected: (String?) -> Unit): () -> Unit