package org.example.project

import androidx.activity.compose.BackHandler
import androidx.compose.runtime.Composable

@Composable
actual fun BackHandlerWrapper(onBack: () -> Unit) {
    BackHandler(onBack = onBack)
}