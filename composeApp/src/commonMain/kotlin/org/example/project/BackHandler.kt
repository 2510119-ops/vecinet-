package org.example.project

import androidx.compose.runtime.Composable

@Composable
expect fun BackHandlerWrapper(onBack: () -> Unit)