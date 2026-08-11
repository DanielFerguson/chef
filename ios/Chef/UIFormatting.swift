import Foundation

extension Int {
    var currencyAUD: String {
        let amount = Decimal(self) / 100
        return amount.formatted(
            .currency(code: "AUD")
                .locale(Locale(identifier: "en_AU"))
        )
    }
}
