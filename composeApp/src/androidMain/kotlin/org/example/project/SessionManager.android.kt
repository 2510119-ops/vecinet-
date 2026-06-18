package org.example.project

import android.content.Context
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.first

private val Context.dataStore by preferencesDataStore(name = "vecinet_session")

actual object SessionManager {

    private val KEY_NOMBRE = stringPreferencesKey("nombre")
    private val KEY_EMAIL  = stringPreferencesKey("email")
    private val KEY_ROL    = stringPreferencesKey("rol")
    private val KEY_FOTO   = stringPreferencesKey("foto")
    private val KEY_FECHA  = stringPreferencesKey("fecha_login")  // ← nuevo

    private const val DIAS_EXPIRACION = 90L

    actual suspend fun guardarSesion(nombre: String, email: String, rol: String, foto: String?) {
        AppContext.get().dataStore.edit { prefs ->
            prefs[KEY_NOMBRE] = nombre
            prefs[KEY_EMAIL]  = email
            prefs[KEY_ROL]    = rol
            prefs[KEY_FOTO]   = foto ?: ""
            prefs[KEY_FECHA]  = System.currentTimeMillis().toString()  // ← guardar fecha
        }
    }

    actual suspend fun obtenerSesion(): SesionUsuario? {
        val prefs = AppContext.get().dataStore.data.first()
        val email = prefs[KEY_EMAIL] ?: return null
        if (email.isBlank()) return null

        // Verificar expiracion
        val fecha = prefs[KEY_FECHA]?.toLongOrNull() ?: return null
        val diasTranscurridos = (System.currentTimeMillis() - fecha) / (1000L * 60 * 60 * 24)
        if (diasTranscurridos > DIAS_EXPIRACION) {
            cerrarSesion()
            return null
        }

        return SesionUsuario(
            nombre = prefs[KEY_NOMBRE] ?: "",
            email  = email,
            rol    = prefs[KEY_ROL]    ?: "",
            foto   = prefs[KEY_FOTO]?.ifBlank { null }
        )
    }

    actual suspend fun cerrarSesion() {
        AppContext.get().dataStore.edit { it.clear() }
    }
}