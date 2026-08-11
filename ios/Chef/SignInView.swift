import SwiftUI

struct SignInView: View {
    @EnvironmentObject private var appState: AppState
    @State private var email = ""
    @State private var password = ""
    @State private var twoFactorCode = ""

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField("Email", text: $email)
                        .textContentType(.username)
                        .keyboardType(.emailAddress)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()

                    SecureField("Password", text: $password)
                        .textContentType(.password)
                        .privacySensitive()

                    if appState.requiresTwoFactor {
                        TextField("Authentication code", text: $twoFactorCode)
                            .textContentType(.oneTimeCode)
                            .keyboardType(.numberPad)
                            .privacySensitive()
                    }
                } header: {
                    Text("Chef account")
                } footer: {
                    if appState.requiresTwoFactor {
                        Text("Enter the current two-factor code, then sign in again.")
                    }
                }

                Section("Server") {
                    TextField("Chef address", text: $appState.serverAddress)
                        .textContentType(.URL)
                        .keyboardType(.URL)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                }

                Section {
                    Button {
                        Task {
                            await appState.signIn(
                                email: email,
                                password: password,
                                twoFactorCode: twoFactorCode
                            )

                            if appState.isAuthenticated {
                                password = ""
                                twoFactorCode = ""
                            }
                        }
                    } label: {
                        HStack {
                            Spacer()
                            if appState.isWorking {
                                ProgressView()
                            } else {
                                Text("Sign in")
                            }
                            Spacer()
                        }
                    }
                    .disabled(
                        appState.isWorking
                            || email.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
                            || password.isEmpty
                            || (appState.requiresTwoFactor && twoFactorCode.isEmpty)
                    )
                }
            }
            .navigationTitle("Welcome to Chef")
        }
    }
}
