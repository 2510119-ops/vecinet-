package org.example.project

import androidx.compose.ui.graphics.Color

// ── Enum de modos de daltonismo ───────────────────────────────
enum class DaltonismMode { NONE, PROTANOPIA, DEUTERANOPIA, TRITANOPIA }

object VecinetColors {
    // ── Modo claro ───────────────────────────────────────────
    val fondoApp     = Color(0xFFE8F0F7)
    val primary      = Color(0xFF1F4E79)
    val secondary    = Color(0xFF2E75B6)
    val accent       = Color(0xFF2E75B6)

    // ── Modo oscuro ──────────────────────────────────────────
    val fondoOscuro    = Color(0xFF121212)
    val primaryOscuro  = Color(0xFF1A1A2E)
    val surfaceOscuro  = Color(0xFF1E1E2E)
    val cardOscuro     = Color(0xFF2A2A3E)
    val accentOscuro   = Color(0xFF4A90D9)
    val textoOscuro    = Color(0xFFE0E0E0)
    val textoSecOscuro = Color(0xFFB0B0B0)

    // ── Semaforo modo claro ──────────────────────────────────
    val rojo     = Color(0xFFE53935)
    val amarillo = Color(0xFFFDD835)
    val verde    = Color(0xFF43A047)

    // ── Semaforo modo oscuro (mas vibrantes) ─────────────────
    val rojoOscuro     = Color(0xFFFF5252)
    val amarilloOscuro = Color(0xFFFFD740)
    val verdeOscuro    = Color(0xFF69F0AE)

    // ── Colores de reemplazo para daltonismo ─────────────────
    // Protanopia: rojo → naranja intenso (visible sin percibir rojo)
    val rojoProtan     = Color(0xFFE87722)
    val rojoProtanOsc  = Color(0xFFFF9A3C)

    // Deuteranopia: verde → azul cielo (distinto del rojo y amarillo)
    val verdeDeutan    = Color(0xFF0088CC)
    val verdeDeutanOsc = Color(0xFF40B4FF)

    // Tritanopia: amarillo → naranja / verde → teal
    val amarilloTritan    = Color(0xFFFF7700)
    val amarilloTritanOsc = Color(0xFFFF9933)
    val verdeTritan       = Color(0xFF009988)
    val verdeTritanOsc    = Color(0xFF1DE9B6)
}

object AppTheme {
    var isDark       : Boolean       = false
    var daltonism    : DaltonismMode = DaltonismMode.NONE
    var highContrast : Boolean       = false

    // ── Colores base ─────────────────────────────────────────
    val fondo  : Color get() = if (isDark) VecinetColors.fondoOscuro   else VecinetColors.fondoApp
    val primary: Color get() = if (isDark) VecinetColors.primaryOscuro else VecinetColors.primary
    val surface: Color get() = if (isDark) VecinetColors.surfaceOscuro else Color(0xFFFFFFFF)
    val card   : Color get() = if (isDark) VecinetColors.cardOscuro    else Color(0xFFFFFFFF)
    val accent : Color get() = if (isDark) VecinetColors.accentOscuro  else VecinetColors.accent
    val navbar : Color get() = if (isDark) VecinetColors.primaryOscuro else VecinetColors.primary

    // ── Texto con soporte de alto contraste ──────────────────
    val texto: Color get() = when {
        highContrast && isDark  -> Color.White
        highContrast && !isDark -> Color.Black
        isDark                  -> VecinetColors.textoOscuro
        else                    -> Color(0xFF1A1A1A)
    }
    val textoSec: Color get() = when {
        highContrast && isDark  -> Color(0xFFEEEEEE)
        highContrast && !isDark -> Color(0xFF1A1A1A)
        isDark                  -> VecinetColors.textoSecOscuro
        else                    -> Color(0xFF666666)
    }

    // ── Semaforo con filtros de daltonismo ───────────────────
    // Cada color consulta primero el filtro activo,
    // luego cae al color normal segun el modo claro/oscuro.
    val rojo: Color get() = when (daltonism) {
        DaltonismMode.PROTANOPIA -> if (isDark) VecinetColors.rojoProtanOsc else VecinetColors.rojoProtan
        else                     -> if (isDark) VecinetColors.rojoOscuro    else VecinetColors.rojo
    }

    val amarillo: Color get() = when (daltonism) {
        DaltonismMode.TRITANOPIA -> if (isDark) VecinetColors.amarilloTritanOsc else VecinetColors.amarilloTritan
        else                     -> if (isDark) VecinetColors.amarilloOscuro    else VecinetColors.amarillo
    }

    val verde: Color get() = when (daltonism) {
        DaltonismMode.DEUTERANOPIA -> if (isDark) VecinetColors.verdeDeutanOsc else VecinetColors.verdeDeutan
        DaltonismMode.TRITANOPIA   -> if (isDark) VecinetColors.verdeTritanOsc else VecinetColors.verdeTritan
        else                       -> if (isDark) VecinetColors.verdeOscuro    else VecinetColors.verde
    }
}