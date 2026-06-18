package org.example.project

import android.annotation.SuppressLint
import android.app.Activity
import android.content.Context
import android.content.IntentSender
import android.location.LocationManager
import com.google.android.gms.common.api.ResolvableApiException
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.LocationSettingsRequest
import com.google.android.gms.location.Priority
import com.google.android.gms.location.SettingsClient
import com.google.android.gms.tasks.CancellationTokenSource
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlin.coroutines.resume

actual class LocationService actual constructor() {

    fun isLocationEnabled(): Boolean {
        val ctx = AppContext.get()
        val lm = ctx.getSystemService(Context.LOCATION_SERVICE) as LocationManager
        return lm.isProviderEnabled(LocationManager.GPS_PROVIDER) ||
                lm.isProviderEnabled(LocationManager.NETWORK_PROVIDER)
    }

    fun solicitarActivarGPS(activity: Activity, onResult: (Boolean) -> Unit) {
        val locationRequest = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, 1000).build()
        val builder = LocationSettingsRequest.Builder().addLocationRequest(locationRequest)
        val client: SettingsClient = LocationServices.getSettingsClient(activity)
        client.checkLocationSettings(builder.build())
            .addOnSuccessListener { onResult(true) }
            .addOnFailureListener { exception ->
                if (exception is ResolvableApiException) {
                    try {
                        exception.startResolutionForResult(activity, 1001)
                        onResult(false)
                    } catch (sendEx: IntentSender.SendIntentException) {
                        onResult(false)
                    }
                } else {
                    onResult(false)
                }
            }
    }

    @SuppressLint("MissingPermission")
    actual suspend fun obtenerUbicacion(): Ubicacion? {
        if (!isLocationEnabled()) return null
        val ctx = AppContext.get()
        val client = LocationServices.getFusedLocationProviderClient(ctx)
        val token = CancellationTokenSource()
        return suspendCancellableCoroutine { cont ->
            client.getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, token.token)
                .addOnSuccessListener { location ->
                    if (location != null) cont.resume(Ubicacion(location.latitude, location.longitude))
                    else cont.resume(null)
                }
                .addOnFailureListener { cont.resume(null) }
            cont.invokeOnCancellation { token.cancel() }
        }
    }
}