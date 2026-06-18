package org.example.project

import android.content.Context

object AppContext {
    private lateinit var context: Context
    fun init(ctx: Context) { context = ctx }
    fun get(): Context = context
}