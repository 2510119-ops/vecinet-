package org.example.project

import android.net.Uri

actual suspend fun leerBytesDeImagen(uri: String): ByteArray? {
    return try {
        val context = AppContext.get()
        val inputStream = context.contentResolver.openInputStream(Uri.parse(uri))
        inputStream?.readBytes().also { inputStream?.close() }
    } catch (e: Exception) {
        null
    }
}